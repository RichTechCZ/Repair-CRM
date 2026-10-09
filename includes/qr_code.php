<?php
/**
 * Self-hosted QR Code generator (ISO/IEC 18004, byte mode, versions 1–10).
 *
 * Replaces the third-party api.qrserver.com image service: customer phone numbers,
 * payment QR data (account + amount) and order links never leave the CRM.
 * Algorithm follows Project Nayuki's reference implementation (MIT).
 */

const CRM_QR_MAX_VERSION = 10;

/** @var array<string,int> format-bits value of each error-correction level */
const CRM_QR_ECL_FORMAT = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];

/** ECC codewords per block, index = version (1..10). */
const CRM_QR_ECC_PER_BLOCK = [
    'L' => [0, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18],
    'M' => [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26],
    'Q' => [0, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24],
    'H' => [0, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28],
];

/** Number of error-correction blocks, index = version (1..10). */
const CRM_QR_NUM_BLOCKS = [
    'L' => [0, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4],
    'M' => [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5],
    'Q' => [0, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8],
    'H' => [0, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8],
];

/**
 * Encode text into a QR module matrix (true = dark).
 *
 * @param int|null $forceMask 0..7 to skip automatic mask selection (used by tests)
 * @return list<list<bool>>
 */
function crmQrEncode(string $text, string $ecl = 'M', ?int $forceVersion = null, ?int $forceMask = null): array
{
    if (!isset(CRM_QR_ECL_FORMAT[$ecl])) {
        throw new InvalidArgumentException('Unknown QR error-correction level.');
    }
    $bytes = array_values(unpack('C*', $text) ?: []);

    $version = null;
    for ($v = $forceVersion ?? 1; $v <= ($forceVersion ?? CRM_QR_MAX_VERSION); $v++) {
        $countBits = $v <= 9 ? 8 : 16;
        if (4 + $countBits + 8 * count($bytes) <= crmQrNumDataCodewords($v, $ecl) * 8) {
            $version = $v;
            break;
        }
    }
    if ($version === null) {
        throw new InvalidArgumentException('Text is too long for a QR code.');
    }

    // Data bit stream: byte mode indicator, character count, data, terminator, padding.
    $bits = [];
    $append = static function (int $value, int $length) use (&$bits): void {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    };
    $append(0x4, 4);
    $append(count($bytes), $version <= 9 ? 8 : 16);
    foreach ($bytes as $byte) {
        $append($byte, 8);
    }
    $capacityBits = crmQrNumDataCodewords($version, $ecl) * 8;
    $append(0, min(4, $capacityBits - count($bits)));
    $append(0, (8 - count($bits) % 8) % 8);
    for ($pad = 0xEC; count($bits) < $capacityBits; $pad ^= 0xEC ^ 0x11) {
        $append($pad, 8);
    }
    $dataCodewords = [];
    for ($i = 0; $i < count($bits); $i += 8) {
        $byte = 0;
        for ($j = 0; $j < 8; $j++) {
            $byte = ($byte << 1) | $bits[$i + $j];
        }
        $dataCodewords[] = $byte;
    }

    $codewords = crmQrAddEccAndInterleave($dataCodewords, $version, $ecl);

    $size = $version * 4 + 17;
    $modules = array_fill(0, $size, array_fill(0, $size, false));
    $isFunction = array_fill(0, $size, array_fill(0, $size, false));
    crmQrDrawFunctionPatterns($modules, $isFunction, $version, $ecl);
    crmQrDrawCodewords($modules, $isFunction, $codewords);

    if ($forceMask === null) {
        $bestMask = 0;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            crmQrApplyMask($modules, $isFunction, $mask);
            crmQrDrawFormatBits($modules, $isFunction, $ecl, $mask);
            $penalty = crmQrPenalty($modules);
            if ($penalty < $bestPenalty) {
                $bestMask = $mask;
                $bestPenalty = $penalty;
            }
            crmQrApplyMask($modules, $isFunction, $mask); // XOR undo
        }
        $forceMask = $bestMask;
    }
    crmQrApplyMask($modules, $isFunction, $forceMask);
    crmQrDrawFormatBits($modules, $isFunction, $ecl, $forceMask);
    return $modules;
}

function crmQrNumRawDataModules(int $version): int
{
    $result = (16 * $version + 128) * $version + 64;
    if ($version >= 2) {
        $numAlign = intdiv($version, 7) + 2;
        $result -= (25 * $numAlign - 10) * $numAlign - 55;
        if ($version >= 7) {
            $result -= 36;
        }
    }
    return $result;
}

function crmQrNumDataCodewords(int $version, string $ecl): int
{
    return intdiv(crmQrNumRawDataModules($version), 8)
        - CRM_QR_ECC_PER_BLOCK[$ecl][$version] * CRM_QR_NUM_BLOCKS[$ecl][$version];
}

function crmQrGfMultiply(int $x, int $y): int
{
    $z = 0;
    for ($i = 7; $i >= 0; $i--) {
        $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
        $z ^= (($y >> $i) & 1) * $x;
    }
    return $z;
}

/** @return list<int> */
function crmQrReedSolomonDivisor(int $degree): array
{
    $result = array_fill(0, $degree, 0);
    $result[$degree - 1] = 1;
    $root = 1;
    for ($i = 0; $i < $degree; $i++) {
        for ($j = 0; $j < $degree; $j++) {
            $result[$j] = crmQrGfMultiply($result[$j], $root);
            if ($j + 1 < $degree) {
                $result[$j] ^= $result[$j + 1];
            }
        }
        $root = crmQrGfMultiply($root, 0x02);
    }
    return $result;
}

/** @return list<int> */
function crmQrReedSolomonRemainder(array $data, array $divisor): array
{
    $result = array_fill(0, count($divisor), 0);
    foreach ($data as $byte) {
        $factor = $byte ^ array_shift($result);
        $result[] = 0;
        foreach ($divisor as $i => $coefficient) {
            $result[$i] ^= crmQrGfMultiply($coefficient, $factor);
        }
    }
    return $result;
}

/** @return list<int> */
function crmQrAddEccAndInterleave(array $data, int $version, string $ecl): array
{
    $numBlocks = CRM_QR_NUM_BLOCKS[$ecl][$version];
    $blockEccLen = CRM_QR_ECC_PER_BLOCK[$ecl][$version];
    $rawCodewords = intdiv(crmQrNumRawDataModules($version), 8);
    $numShortBlocks = $numBlocks - $rawCodewords % $numBlocks;
    $shortBlockLen = intdiv($rawCodewords, $numBlocks);

    $divisor = crmQrReedSolomonDivisor($blockEccLen);
    $blocks = [];
    $k = 0;
    for ($i = 0; $i < $numBlocks; $i++) {
        $length = $shortBlockLen - $blockEccLen + ($i < $numShortBlocks ? 0 : 1);
        $block = array_slice($data, $k, $length);
        $k += $length;
        $ecc = crmQrReedSolomonRemainder($block, $divisor);
        if ($i < $numShortBlocks) {
            $block[] = 0; // placeholder, skipped when interleaving
        }
        $blocks[] = array_merge($block, $ecc);
    }

    $result = [];
    $blockLength = count($blocks[0]);
    for ($i = 0; $i < $blockLength; $i++) {
        foreach ($blocks as $j => $block) {
            if ($i !== $shortBlockLen - $blockEccLen || $j >= $numShortBlocks) {
                $result[] = $block[$i];
            }
        }
    }
    return $result;
}

function crmQrSetFunction(array &$modules, array &$isFunction, int $x, int $y, bool $dark): void
{
    $modules[$y][$x] = $dark;
    $isFunction[$y][$x] = true;
}

function crmQrDrawFunctionPatterns(array &$modules, array &$isFunction, int $version, string $ecl): void
{
    $size = count($modules);
    for ($i = 0; $i < $size; $i++) {
        crmQrSetFunction($modules, $isFunction, 6, $i, $i % 2 === 0);
        crmQrSetFunction($modules, $isFunction, $i, 6, $i % 2 === 0);
    }
    foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $cx + $dx;
                $y = $cy + $dy;
                if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) {
                    $distance = max(abs($dx), abs($dy));
                    crmQrSetFunction($modules, $isFunction, $x, $y, $distance !== 2 && $distance !== 4);
                }
            }
        }
    }

    $positions = [];
    if ($version > 1) {
        $numAlign = intdiv($version, 7) + 2;
        $step = (int)(ceil(($version * 4 + 4) / ($numAlign * 2 - 2)) * 2);
        $positions = [6];
        for ($pos = $size - 7; count($positions) < $numAlign; $pos -= $step) {
            array_splice($positions, 1, 0, [$pos]);
        }
    }
    $numAlign = count($positions);
    for ($i = 0; $i < $numAlign; $i++) {
        for ($j = 0; $j < $numAlign; $j++) {
            if (($i === 0 && $j === 0) || ($i === 0 && $j === $numAlign - 1) || ($i === $numAlign - 1 && $j === 0)) {
                continue;
            }
            for ($dy = -2; $dy <= 2; $dy++) {
                for ($dx = -2; $dx <= 2; $dx++) {
                    crmQrSetFunction($modules, $isFunction, $positions[$i] + $dx, $positions[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                }
            }
        }
    }

    crmQrDrawFormatBits($modules, $isFunction, $ecl, 0); // reserve; overwritten after masking

    if ($version >= 7) {
        $remainder = $version;
        for ($i = 0; $i < 12; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
        }
        $versionBits = ($version << 12) | $remainder;
        for ($i = 0; $i < 18; $i++) {
            $dark = (($versionBits >> $i) & 1) === 1;
            $a = $size - 11 + $i % 3;
            $b = intdiv($i, 3);
            crmQrSetFunction($modules, $isFunction, $a, $b, $dark);
            crmQrSetFunction($modules, $isFunction, $b, $a, $dark);
        }
    }
}

function crmQrDrawFormatBits(array &$modules, array &$isFunction, string $ecl, int $mask): void
{
    $data = (CRM_QR_ECL_FORMAT[$ecl] << 3) | $mask;
    $remainder = $data;
    for ($i = 0; $i < 10; $i++) {
        $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
    }
    $bits = (($data << 10) | $remainder) ^ 0x5412;
    $bit = static function (int $i) use ($bits): bool {
        return (($bits >> $i) & 1) === 1;
    };
    $size = count($modules);

    for ($i = 0; $i <= 5; $i++) {
        crmQrSetFunction($modules, $isFunction, 8, $i, $bit($i));
    }
    crmQrSetFunction($modules, $isFunction, 8, 7, $bit(6));
    crmQrSetFunction($modules, $isFunction, 8, 8, $bit(7));
    crmQrSetFunction($modules, $isFunction, 7, 8, $bit(8));
    for ($i = 9; $i < 15; $i++) {
        crmQrSetFunction($modules, $isFunction, 14 - $i, 8, $bit($i));
    }
    for ($i = 0; $i < 8; $i++) {
        crmQrSetFunction($modules, $isFunction, $size - 1 - $i, 8, $bit($i));
    }
    for ($i = 8; $i < 15; $i++) {
        crmQrSetFunction($modules, $isFunction, 8, $size - 15 + $i, $bit($i));
    }
    crmQrSetFunction($modules, $isFunction, 8, $size - 8, true); // dark module
}

function crmQrDrawCodewords(array &$modules, array $isFunction, array $codewords): void
{
    $size = count($modules);
    $totalBits = count($codewords) * 8;
    $i = 0;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) {
            $right = 5;
        }
        for ($vert = 0; $vert < $size; $vert++) {
            for ($j = 0; $j < 2; $j++) {
                $x = $right - $j;
                $upward = (($right + 1) & 2) === 0;
                $y = $upward ? $size - 1 - $vert : $vert;
                if (!$isFunction[$y][$x] && $i < $totalBits) {
                    $modules[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                    $i++;
                }
            }
        }
    }
}

function crmQrApplyMask(array &$modules, array $isFunction, int $mask): void
{
    $size = count($modules);
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            switch ($mask) {
                case 0: $invert = ($x + $y) % 2 === 0; break;
                case 1: $invert = $y % 2 === 0; break;
                case 2: $invert = $x % 3 === 0; break;
                case 3: $invert = ($x + $y) % 3 === 0; break;
                case 4: $invert = (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0; break;
                case 5: $invert = $x * $y % 2 + $x * $y % 3 === 0; break;
                case 6: $invert = ($x * $y % 2 + $x * $y % 3) % 2 === 0; break;
                default: $invert = (($x + $y) % 2 + $x * $y % 3) % 2 === 0; break;
            }
            if ($invert && !$isFunction[$y][$x]) {
                $modules[$y][$x] = !$modules[$y][$x];
            }
        }
    }
}

function crmQrPenalty(array $modules): int
{
    $size = count($modules);
    $result = 0;

    $addHistory = static function (int $runLength, array &$history) use ($size): void {
        if ($history[0] === 0) {
            $runLength += $size; // add light border to the initial run
        }
        array_pop($history);
        array_unshift($history, $runLength);
    };
    $countPatterns = static function (array $h): int {
        $n = $h[1];
        $core = $n > 0 && $h[2] === $n && $h[3] === $n * 3 && $h[4] === $n && $h[5] === $n;
        return ($core && $h[0] >= $n * 4 && $h[6] >= $n ? 1 : 0) + ($core && $h[6] >= $n * 4 && $h[0] >= $n ? 1 : 0);
    };
    $lines = static function (bool $columns) use ($modules, $size, $addHistory, $countPatterns): int {
        $penalty = 0;
        for ($a = 0; $a < $size; $a++) {
            $runColor = false;
            $runLength = 0;
            $history = array_fill(0, 7, 0);
            for ($b = 0; $b < $size; $b++) {
                $color = $columns ? $modules[$b][$a] : $modules[$a][$b];
                if ($color === $runColor) {
                    $runLength++;
                    if ($runLength === 5) {
                        $penalty += 3;
                    } elseif ($runLength > 5) {
                        $penalty++;
                    }
                } else {
                    $addHistory($runLength, $history);
                    if (!$runColor) {
                        $penalty += $countPatterns($history) * 40;
                    }
                    $runColor = $color;
                    $runLength = 1;
                }
            }
            if ($runColor) {
                $addHistory($runLength, $history);
                $runLength = 0;
            }
            $runLength += $size;
            $addHistory($runLength, $history);
            $penalty += $countPatterns($history) * 40;
        }
        return $penalty;
    };
    $result += $lines(false) + $lines(true);

    $dark = 0;
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            if ($modules[$y][$x]) {
                $dark++;
            }
            if ($y < $size - 1 && $x < $size - 1) {
                $c = $modules[$y][$x];
                if ($c === $modules[$y][$x + 1] && $c === $modules[$y + 1][$x] && $c === $modules[$y + 1][$x + 1]) {
                    $result += 3;
                }
            }
        }
    }
    $total = $size * $size;
    $k = (int)ceil(abs($dark * 20 - $total * 10) / $total) - 1;
    return $result + $k * 10;
}

/**
 * SVG markup of a QR code (crisp at any print size, 4-module quiet zone).
 */
function crmQrSvg(string $text, string $ecl = 'M'): string
{
    $modules = crmQrEncode($text, $ecl);
    $size = count($modules);
    $border = 4;
    $path = '';
    foreach ($modules as $y => $row) {
        foreach ($row as $x => $dark) {
            if ($dark) {
                $path .= 'M' . ($x + $border) . ',' . ($y + $border) . 'h1v1h-1z';
            }
        }
    }
    $dimension = $size + 2 * $border;
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dimension . ' ' . $dimension . '" shape-rendering="crispEdges">'
        . '<rect width="100%" height="100%" fill="#fff"/><path fill="#000" d="' . $path . '"/></svg>';
}

/**
 * data: URI for <img src> (CSP img-src already allows data:).
 */
function crmQrDataUri(string $text, string $ecl = 'M'): string
{
    return 'data:image/svg+xml;base64,' . base64_encode(crmQrSvg($text, $ecl));
}
