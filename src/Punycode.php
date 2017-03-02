<?php

namespace Finwo\Punycode;

/**
 * Class Punycode
 *
 * Fully static Punycode en-/decoder based on https://www.ietf.org/rfc/rfc3492.txt
 * This encoder does not limit string sizes, like https://github.com/true/php-punycode does
 *
 * @package Finwo\Punycode
 */
class Punycode
{
    /**
     * Bootstring parameter values
     *
     */
    const BASE         = 36;
    const DAMP         = 700;
    const DELIMITER    = '-';
    const INITIAL_BIAS = 72;
    const INITIAL_N    = 128;
    const PREFIX       = 'xn--';
    const SKEW         = 38;
    const TMAX         = 26;
    const TMIN         = 1;

    /**
     * See page 9 of the RFC
     *
     * @var array
     */
    protected static $decodeTable = array(
        'a' =>  0, 'b' =>  1, 'c' =>  2, 'd' =>  3, 'e' =>  4, 'f' =>  5,
        'g' =>  6, 'h' =>  7, 'i' =>  8, 'j' =>  9, 'k' => 10, 'l' => 11,
        'm' => 12, 'n' => 13, 'o' => 14, 'p' => 15, 'q' => 16, 'r' => 17,
        's' => 18, 't' => 19, 'u' => 20, 'v' => 21, 'w' => 22, 'x' => 23,
        'y' => 24, 'z' => 25, '0' => 26, '1' => 27, '2' => 28, '3' => 29,
        '4' => 30, '5' => 31, '6' => 32, '7' => 33, '8' => 34, '9' => 35,
    );

    /**
     * This will be build during __construct
     *
     * @var array
     */
    protected static $encodeTable = array();

    /**
     * @var bool
     */
    protected static $initialized = false;

    /**
     * @return array
     */
    protected static function buildEncodeTable()
    {
        if (!count(self::$encodeTable)) {
            self::$encodeTable = array_keys(self::$decodeTable);
        }

        return self::$encodeTable;
    }

    /**
     * Initialize the encoder if needed
     */
    protected static function init()
    {
        if (self::$initialized) {
            return;
        }
        self::buildEncodeTable();
    }

    /**
     * List code points for a given input
     *
     * @param string $input
     *
     * @return array Multi-dimension array with basic, non-basic and aggregated code points
     */
    protected static function listCodePoints($input)
    {
        $codePoints = array(
            'all'      => array(),
            'basic'    => array(),
            'nonBasic' => array(),
        );

        $length = mb_strlen($input);
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($input, $i, 1);
            $code = self::charToCodePoint($char);
            if ($code < 128) {
                $codePoints['all'][] = $codePoints['basic'][] = $code;
            } else {
                $codePoints['all'][] = $codePoints['nonBasic'][] = $code;
            }
        }

        return $codePoints;
    }

    /**
     * Convert a single or multi-byte character to its code point
     *
     * @param string $char
     *
     * @return integer
     */
    protected static function charToCodePoint($char)
    {
        $code = ord($char[0]);
        if ($code < 128) {
            return $code;
        } elseif ($code < 224) {
            return (($code - 192) * 64) + (ord($char[1]) - 128);
        } elseif ($code < 240) {
            return (($code - 224) * 4096) + ((ord($char[1]) - 128) * 64) + (ord($char[2]) - 128);
        } else {
            return (($code - 240) * 262144) + ((ord($char[1]) - 128) * 4096) + ((ord($char[2]) - 128) * 64) + (ord($char[3]) - 128);
        }
    }

    /**
     * Convert a code point to its single or multi-byte character
     *
     * @param integer $code
     *
     * @return string
     */
    protected static function codePointToChar($code)
    {
        if ($code <= 0x7F) {
            return chr($code);
        } elseif ($code <= 0x7FF) {
            return chr(($code >> 6) + 192) . chr(($code & 63) + 128);
        } elseif ($code <= 0xFFFF) {
            return chr(($code >> 12) + 224) . chr((($code >> 6) & 63) + 128) . chr(($code & 63) + 128);
        } else {
            return chr(($code >> 18) + 240) . chr((($code >> 12) & 63) + 128) . chr((($code >> 6) & 63) + 128) . chr(($code & 63) + 128);
        }
    }

    /**
     * Calculate the bias threshold to fall between TMIN and TMAX
     *
     * @param integer $k
     * @param integer $bias
     *
     * @return integer
     */
    protected static function calculateThreshold($k, $bias)
    {
        if ($k <= $bias + static::TMIN) {
            return static::TMIN;
        } elseif ($k >= $bias + static::TMAX) {
            return static::TMAX;
        }

        return $k - $bias;
    }

    /**
     * Bias adaptation
     *
     * @param integer $delta
     * @param integer $numPoints
     * @param boolean $firstTime
     *
     * @return integer
     */
    protected static function adapt($delta, $numPoints, $firstTime)
    {
        $delta = (int)(
        ($firstTime)
            ? $delta / static::DAMP
            : $delta / 2
        );
        $delta += (int)($delta / $numPoints);

        $k = 0;
        while ($delta > ((static::BASE - static::TMIN) * static::TMAX) / 2) {
            $delta = (int)($delta / (static::BASE - static::TMIN));
            $k     = $k + static::BASE;
        }
        $k = $k + (int)(((static::BASE - static::TMIN + 1) * $delta) / ($delta + static::SKEW));

        return $k;
    }

    /**
     * @param string $input
     *
     * @return string $encodedString
     */
    public static function encode($input)
    {
        self::init();
        $codePoints = self::listCodePoints($input);

        $n     = static::INITIAL_N;
        $bias  = static::INITIAL_BIAS;
        $delta = 0;
        $h     = $b = count($codePoints['basic']);

        $output = '';
        foreach ($codePoints['basic'] as $code) {
            $output .= self::codePointToChar($code);
        }
        if ($input === $output) {
            return $output;
        }
        if ($b > 0) {
            $output .= static::DELIMITER;
        }

        $codePoints['nonBasic'] = array_unique($codePoints['nonBasic']);
        sort($codePoints['nonBasic']);

        $i      = 0;
        $length = mb_strlen($input);
        while ($h < $length) {
            $m     = $codePoints['nonBasic'][$i++];
            $delta = $delta + ($m - $n) * ($h + 1);
            $n     = $m;

            foreach ($codePoints['all'] as $c) {
                if ($c < $n || $c < static::INITIAL_N) {
                    $delta++;
                }
                if ($c === $n) {
                    $q = $delta;
                    for ($k = static::BASE; ; $k += static::BASE) {
                        $t = self::calculateThreshold($k, $bias);
                        if ($q < $t) {
                            break;
                        }

                        $code = $t + (($q - $t) % (static::BASE - $t));
                        $output .= static::$encodeTable[$code];

                        $q = ($q - $t) / (static::BASE - $t);
                    }

                    $output .= static::$encodeTable[$q];
                    $bias  = self::adapt($delta, $h + 1, ($h === $b));
                    $delta = 0;
                    $h++;
                }
            }

            $delta++;
            $n++;
        }
        $out = static::PREFIX . $output;

        return $out;
    }

    /**
     * @param string $encodedString
     *
     * @return string $decodedString
     */
    public static function decode($encodedString)
    {
        self::init();
        if (!self::isPunycode($encodedString)) {
            return $encodedString;
        }
        $encodedString = substr($encodedString, strlen(static::PREFIX));
        $n             = static::INITIAL_N;
        $i             = 0;
        $bias          = static::INITIAL_BIAS;
        $output        = '';

        $pos = strrpos($encodedString, static::DELIMITER);
        if ($pos !== false) {
            $output = substr($encodedString, 0, $pos++);
        } else {
            $pos = 0;
        }

        $outputLength = strlen($output);
        $inputLength  = strlen($encodedString);
        while ($pos < $inputLength) {
            $oldi = $i;
            $w    = 1;

            for ($k = static::BASE; ; $k += static::BASE) {
                $digit = static::$decodeTable[$encodedString[$pos++]];
                $i     = $i + ($digit * $w);
                $t     = self::calculateThreshold($k, $bias);

                if ($digit < $t) {
                    break;
                }

                $w = $w * (static::BASE - $t);
            }

            $bias   = self::adapt($i - $oldi, ++$outputLength, ($oldi === 0));
            $n      = $n + (int)($i / $outputLength);
            $i      = $i % ($outputLength);
            $output = mb_substr($output, 0, $i) . self::codePointToChar($n) . mb_substr($output, $i, $outputLength - 1);

            $i++;
        }

        return $output;
    }

    /**
     * @param string $stringToCheck
     *
     * @return bool
     */
    public static function isPunycode($stringToCheck)
    {
        if (substr($stringToCheck, 0, strlen(static::PREFIX)) != static::PREFIX) {
            return false;
        }
        if (strpos($stringToCheck, static::DELIMITER, strlen(static::PREFIX)) === false) {
            return false;
        }

        return true;
    }
}
