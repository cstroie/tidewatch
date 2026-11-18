<?php
declare(strict_types=1);

/**
 * Minimal SNMP access. Values come back as plain strings/numbers, OIDs as
 * numeric strings without a leading dot. Missing OIDs are omitted.
 */
interface SnmpDriver
{
    /** @return array<string,string> oid => value */
    public function get(array $oids): array;
    /** @return array<string,string> oid => value */
    public function walk(string $oid): array;
}

function snmp_open(array $dev): SnmpDriver
{
    $want = config('snmp_driver', 'auto');
    if ($want === 'ext' || ($want === 'auto' && class_exists('SNMP'))) {
        return new SnmpExt($dev);
    }
    return new SnmpCli($dev);
}

function oid_norm(string $oid): string
{
    return ltrim(trim($oid), '.');
}

final class SnmpExt implements SnmpDriver
{
    private SNMP $s;

    public function __construct(array $d)
    {
        $ver = ['1' => SNMP::VERSION_1, '2c' => SNMP::VERSION_2c, '3' => SNMP::VERSION_3][$d['version']] ?? SNMP::VERSION_2c;
        $host = (str_contains($d['host'], ':') ? "udp6:[{$d['host']}]" : $d['host']) . ':' . (int)$d['port'];
        $timeout = (int)(config('snmp_timeout', 1.0) * 1e6);
        $retries = (int)config('snmp_retries', 1);
        $sec = $ver === SNMP::VERSION_3 ? (string)$d['v3_user'] : (string)$d['community'];
        $this->s = new SNMP($ver, $host, $sec, $timeout, $retries);
        if ($ver === SNMP::VERSION_3) {
            $this->s->setSecurity($d['v3_level'] ?: 'authPriv', $d['v3_auth_proto'] ?: 'SHA', (string)$d['v3_auth_pass'],
                $d['v3_priv_proto'] ?: 'AES', (string)$d['v3_priv_pass']);
        }
        $this->s->valueretrieval = SNMP_VALUE_PLAIN;
        $this->s->oid_output_format = SNMP_OID_OUTPUT_NUMERIC;
        $this->s->quick_print = true;
        $this->s->enum_print = false;
        $this->s->exceptions_enabled = 0;
    }

    public function get(array $oids): array
    {
        $out = [];
        foreach (array_chunk($oids, 20) as $chunk) {
            $r = @$this->s->get($chunk, true);
            if ($r === false) {
                if (count($chunk) > 1 && $this->s->getErrno() !== SNMP::ERRNO_TIMEOUT) {
                    // v1 fails the whole PDU on one bad OID: retry one by one
                    foreach ($chunk as $o) {
                        $out += $this->get([$o]);
                    }
                    continue;
                }
                if ($this->s->getErrno() === SNMP::ERRNO_TIMEOUT) {
                    throw new RuntimeException('timeout');
                }
                continue;
            }
            foreach ($r as $k => $v) {
                if (is_string($v) && preg_match('/^No Such|^No more variables/', $v)) {
                    continue;
                }
                $out[oid_norm((string)$k)] = (string)$v;
            }
        }
        return $out;
    }

    public function walk(string $oid): array
    {
        $r = @$this->s->walk($oid, false, 25);
        if ($r === false && $this->s->getErrno() === SNMP::ERRNO_TIMEOUT) {
            throw new RuntimeException('timeout');
        }
        $out = [];
        foreach ((array)$r as $k => $v) {
            if (!is_string($k) || !str_contains($k, '.')) {
                continue; // missing subtree comes back as [0 => '']
            }
            $out[oid_norm($k)] = (string)$v;
        }
        return $out;
    }
}

final class SnmpCli implements SnmpDriver
{
    private array $args;

    public function __construct(array $d)
    {
        $a = ['-v', $d['version'] === '1' ? '1' : ($d['version'] === '3' ? '3' : '2c'),
              '-t', (string)config('snmp_timeout', 1.0), '-r', (string)config('snmp_retries', 1),
              '-On', '-Oq', '-Ot', '-Oe', '-OU'];
        if ($d['version'] === '3') {
            $lvl = $d['v3_level'] ?: 'authPriv';
            array_push($a, '-l', $lvl, '-u', (string)$d['v3_user']);
            if ($lvl !== 'noAuthNoPriv') {
                array_push($a, '-a', $d['v3_auth_proto'] ?: 'SHA', '-A', (string)$d['v3_auth_pass']);
            }
            if ($lvl === 'authPriv') {
                array_push($a, '-x', $d['v3_priv_proto'] ?: 'AES', '-X', (string)$d['v3_priv_pass']);
            }
        } else {
            array_push($a, '-c', (string)$d['community']);
        }
        $host = str_contains($d['host'], ':') ? "udp6:[{$d['host']}]" : $d['host'];
        $a[] = $host . ':' . (int)$d['port'];
        $this->args = $a;
    }

    private function run(string $bin, array $oids): array
    {
        $cmd = $bin . ' ' . implode(' ', array_map('escapeshellarg', array_merge($this->args, $oids))) . ' 2>&1';
        exec($cmd, $lines, $rc);
        $out = [];
        foreach ($lines as $l) {
            if (stripos($l, 'Timeout') === 0) {
                throw new RuntimeException('timeout');
            }
            if (!preg_match('/^\.?([\d.]+)\s+(.*)$/', $l, $m)) {
                continue;
            }
            $v = $m[2];
            if (preg_match('/^No Such|^No more variables/', $v)) {
                continue;
            }
            if (strlen($v) >= 2 && $v[0] === '"' && str_ends_with($v, '"')) {
                $v = stripcslashes(substr($v, 1, -1));
            }
            $out[oid_norm($m[1])] = $v;
        }
        return $out;
    }

    public function get(array $oids): array
    {
        $out = [];
        foreach (array_chunk($oids, 20) as $chunk) {
            $out += $this->run('snmpget', $chunk);
        }
        return $out;
    }

    public function walk(string $oid): array
    {
        return $this->run(str_contains(implode(' ', $this->args), '-v 1') ? 'snmpwalk' : 'snmpbulkwalk', [$oid]);
    }
}

/** Index table walk: [suffix => value] for a column OID. */
function snmp_column(SnmpDriver $s, string $col): array
{
    $col = oid_norm($col);
    $out = [];
    foreach ($s->walk($col) as $oid => $v) {
        $oid = (string)$oid;
        if (str_starts_with($oid, $col . '.')) {
            $out[substr($oid, strlen($col) + 1)] = $v;
        }
    }
    return $out;
}
