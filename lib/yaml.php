<?php
function yaml_parse_simple($filename) {
    $lines = file($filename, FILE_IGNORE_NEW_LINES);
    $data = [];
    $stack = [ [ 'indent' => -1, 'data' => &$data ] ];
    foreach ($lines as $line) {
        if (trim($line) === '' || preg_match('/^\s*#/', $line)) continue;
        if (!preg_match('/^(\s*)(.*)$/', $line, $m)) continue;
        $indent = strlen($m[1]);
        $content = trim($m[2]);
        while ($indent <= end($stack)['indent']) array_pop($stack);
        $parent = &$stack[count($stack)-1]['data'];
        if (preg_match('/^-\s*(.*)$/', $content, $mm)) {
            if (!is_array($parent)) $parent = [];
            $parent[] = yaml_parse_value($mm[1]);
            continue;
        }
        if (strpos($content, ':') !== false) {
            list($key, $val) = array_map('trim', explode(':', $content, 2));
            if ($val === '') {
                $parent[$key] = [];
                $stack[] = [ 'indent' => $indent, 'data' => &$parent[$key] ];
            } else {
                $parent[$key] = yaml_parse_value($val);
            }
        }
    }
    return $data;
}
function yaml_parse_value($val) {
    if ($val === 'true') return true;
    if ($val === 'false') return false;
    if ($val === 'null') return null;
    if (is_numeric($val)) return $val + 0;
    if ((strlen($val) > 1) && (($val[0] === '"' && $val[strlen($val)-1] === '"') || ($val[0]==="'" && $val[strlen($val)-1]==="'"))) {
        return substr($val,1,-1);
    }
    return $val;
}
