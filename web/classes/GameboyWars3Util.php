<?php
	require_once("DBUtil.php");
/**
 * Utility class for Game Boy Wars 3 (CGB-BWWJ/CGB-BWWE) operations
 */
class GameboyWars3Util {

    // The 5 mercenary units youhei_menu.php prices, in the fixed order the
    // game reads them (spec comment already on that file: 0=Infantry,
    // 1=AA Tank, 2=Tank, 3=Bomber, 4=Frigate). Not region-specific: the
    // route never branched on region for this endpoint before today, and
    // pricing is game balance, not language.
    const MERCENARY_UNITS = ["Infantry", "AA Tank", "Tank", "Bomber", "Frigate"];

    /**
     * Map header bytes for each game version
     * These are prepended to map data when serving downloads
     */
    private static array $mapHeaders = [
        'j' => "\x20\x00", // Japanese (CGB-BWWJ)
        'e' => "\x21\x00", // English translation (CGB-BWWE)
    ];

    /**
     * Map category text at offset 0x10-0x18 (in full file with header)
     * This is 0x0E-0x16 in stored data without header.
     * Displayed on the third line of the map details box in-game.
     * Keys are game region codes (j=Japanese, e=English).
     * Note: Category field is 9 bytes max. English uses uppercase only.
     */
    private static array $mapCategories = [
        'j' => 'オフィシャルマップ',
        'e' => 'OFFICIAL',  // 8 chars, fits in 9-byte limit
    ];

    /**
     * Map ID ranges:
     * - 0000-1999: Official maps (from original game)
     * - 2000-9999: REON designer maps
     */
    public const MAP_ID_OFFICIAL_MAX = 1999;
    public const MAP_ID_REON_MIN = 2000;
    public const MAP_ID_REON_MAX = 9999;

    /**
     * Game Boy Wars 3 character encoding map (byte -> UTF-8)
     * Derived from gbwars3-en decompilation char_main.inc
     */
    private static array $charMapDecode = [
        // Control characters
        0x00 => '', // [ED] - end marker
        0x01 => "\n", // [LF] - line feed
        // Symbols 0x10-0x1E
        0x10 => '•', 0x11 => '◀', 0x12 => '▶', 0x13 => '▼', 0x14 => '▲',
        0x15 => '♪', 0x16 => '〇', 0x17 => '〜', 0x18 => '♥',
        0x1b => '「', 0x1c => '|', 0x1d => '」', 0x1e => '\\',
        // ASCII 0x20-0x5F (mostly standard, with exceptions)
        0x2d => 'ー', 0x2e => '。', 0x5c => '¥',
        // Hiragana 0x60-0xAF
        0x60 => 'を', 0x61 => 'あ', 0x62 => 'い', 0x63 => 'う', 0x64 => 'え',
        0x65 => 'お', 0x66 => 'か', 0x67 => 'き', 0x68 => 'く', 0x69 => 'け',
        0x6a => 'こ', 0x6b => 'さ', 0x6c => 'し', 0x6d => 'す', 0x6e => 'せ',
        0x6f => 'そ', 0x70 => 'た', 0x71 => 'ち', 0x72 => 'つ', 0x73 => 'て',
        0x74 => 'と', 0x75 => 'な', 0x76 => 'に', 0x77 => 'ぬ', 0x78 => 'ね',
        0x79 => 'の', 0x7a => 'は', 0x7b => 'ひ', 0x7c => 'ふ', 0x7d => 'へ',
        0x7e => 'ほ', 0x7f => 'ま', 0x80 => 'み', 0x81 => 'む', 0x82 => 'め',
        0x83 => 'も', 0x84 => 'や', 0x85 => 'ゆ', 0x86 => 'よ', 0x87 => 'ら',
        0x88 => 'り', 0x89 => 'る', 0x8a => 'れ', 0x8b => 'ろ', 0x8c => 'わ',
        0x8d => 'ん', 0x8e => 'が', 0x8f => 'ぎ', 0x90 => 'ぐ', 0x91 => 'げ',
        0x92 => 'ご', 0x93 => 'ざ', 0x94 => 'じ', 0x95 => 'ず', 0x96 => 'ぜ',
        0x97 => 'ぞ', 0x98 => 'だ', 0x99 => 'ぢ', 0x9a => 'づ', 0x9b => 'で',
        0x9c => 'ど', 0x9d => 'ば', 0x9e => 'び', 0x9f => 'ぶ', 0xa0 => 'べ',
        0xa1 => 'ぼ', 0xa2 => 'ぱ', 0xa3 => 'ぴ', 0xa4 => 'ぷ', 0xa5 => 'ぺ',
        0xa6 => 'ぽ', 0xa7 => 'ぁ', 0xa8 => 'ぃ', 0xa9 => 'ぅ', 0xaa => 'ぇ',
        0xab => 'ぉ', 0xac => 'ゃ', 0xad => 'ゅ', 0xae => 'ょ', 0xaf => 'っ',
        // Katakana 0xB0-0xFF
        0xb0 => '★', 0xb1 => 'ア', 0xb2 => 'イ', 0xb3 => 'ウ', 0xb4 => 'エ',
        0xb5 => 'オ', 0xb6 => 'カ', 0xb7 => 'キ', 0xb8 => 'ク', 0xb9 => 'ケ',
        0xba => 'コ', 0xbb => 'サ', 0xbc => 'シ', 0xbd => 'ス', 0xbe => 'セ',
        0xbf => 'ソ', 0xc0 => 'タ', 0xc1 => 'チ', 0xc2 => 'ツ', 0xc3 => 'テ',
        0xc4 => 'ト', 0xc5 => 'ナ', 0xc6 => 'ニ', 0xc7 => 'ヌ', 0xc8 => 'ネ',
        0xc9 => 'ノ', 0xca => 'ハ', 0xcb => 'ヒ', 0xcc => 'フ', 0xcd => 'ヘ',
        0xce => 'ホ', 0xcf => 'マ', 0xd0 => 'ミ', 0xd1 => 'ム', 0xd2 => 'メ',
        0xd3 => 'モ', 0xd4 => 'ヤ', 0xd5 => 'ユ', 0xd6 => 'ヨ', 0xd7 => 'ラ',
        0xd8 => 'リ', 0xd9 => 'ル', 0xda => 'レ', 0xdb => 'ロ', 0xdc => 'ワ',
        0xdd => 'ン', 0xde => 'ガ', 0xdf => 'ギ', 0xe0 => 'グ', 0xe1 => 'ゲ',
        0xe2 => 'ゴ', 0xe3 => 'ザ', 0xe4 => 'ジ', 0xe5 => 'ズ', 0xe6 => 'ゼ',
        0xe7 => 'ゾ', 0xe8 => 'ダ', 0xe9 => 'ヂ', 0xea => 'ヅ', 0xeb => 'デ',
        0xec => 'ド', 0xed => 'バ', 0xee => 'ビ', 0xef => 'ブ', 0xf0 => 'ベ',
        0xf1 => 'ボ', 0xf2 => 'パ', 0xf3 => 'ピ', 0xf4 => 'プ', 0xf5 => 'ペ',
        0xf6 => 'ポ', 0xf7 => 'ァ', 0xf8 => 'ィ', 0xf9 => 'ゥ', 0xfa => 'ェ',
        0xfb => 'ォ', 0xfc => 'ャ', 0xfd => 'ュ', 0xfe => 'ョ', 0xff => 'ッ',
    ];

    /** @var array|null Reverse lookup table (UTF-8 -> byte), built on first use */
    private static ?array $charMapEncode = null;

    /**
     * Get the map header bytes for a specific game region
     */
    public static function getMapHeader(string $gameRegion): string {
        return self::$mapHeaders[$gameRegion] ?? self::$mapHeaders['j'];
    }

    /**
     * Build the reverse encoding lookup table on first use
     */
    private static function buildEncodeLookup(): void {
        if (self::$charMapEncode !== null) {
            return;
        }

        self::$charMapEncode = [];

        // Add all special mappings from decode table
        foreach (self::$charMapDecode as $byte => $char) {
            if ($char !== '') {
                self::$charMapEncode[$char] = $byte;
            }
        }

        // Add standard ASCII range (0x20-0x5F) that aren't overridden
        for ($i = 0x20; $i <= 0x5F; $i++) {
            $char = chr($i);
            if (!isset(self::$charMapEncode[$char]) && !isset(self::$charMapDecode[$i])) {
                self::$charMapEncode[$char] = $i;
            }
        }

        // Add hyphen-minus as alternative for ー (long vowel mark)
        self::$charMapEncode['-'] = 0x2d;
        // Add period as alternative for 。
        self::$charMapEncode['.'] = 0x2e;
    }

    /**
     * Decode a map name from game encoding to UTF-8
     *
     * @param string $encoded Raw bytes from map file
     * @return string UTF-8 decoded name
     */
    public static function decodeMapName(string $encoded): string {
        $result = '';
        $len = strlen($encoded);

        for ($i = 0; $i < $len; $i++) {
            $byte = ord($encoded[$i]);

            // Skip null bytes and control characters used as padding
            if ($byte === 0x00 || ($byte >= 0x02 && $byte <= 0x0F) || $byte === 0x19 || $byte === 0x1a) {
                continue;
            }

            if (isset(self::$charMapDecode[$byte])) {
                $result .= self::$charMapDecode[$byte];
            } elseif ($byte >= 0x20 && $byte <= 0x5F) {
                // Standard ASCII range (except special cases already in map)
                $result .= chr($byte);
            } else {
                // Unknown byte - skip or use replacement character
                $result .= '?';
            }
        }

        return $result;
    }

    /**
     * Encode a UTF-8 string to game encoding for map names
     *
     * Note: The game only supports uppercase ASCII letters (A-Z), not lowercase.
     * Lowercase letters will be converted to uppercase automatically.
     *
     * @param string $text UTF-8 text to encode
     * @param int $maxBytes Maximum bytes in output (default 8 for map names)
     * @return string Encoded bytes, padded with nulls to $maxBytes
     */
    public static function encodeMapName(string $text, int $maxBytes = 8): string {
        self::buildEncodeLookup();

        $result = '';
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($chars as $char) {
            if (strlen($result) >= $maxBytes) {
                break;
            }

            // Try the character as-is first
            if (isset(self::$charMapEncode[$char])) {
                $result .= chr(self::$charMapEncode[$char]);
            } elseif (ctype_lower($char)) {
                // Convert lowercase ASCII to uppercase
                $upper = strtoupper($char);
                if (isset(self::$charMapEncode[$upper])) {
                    $result .= chr(self::$charMapEncode[$upper]);
                }
            } else {
                // Character not in encoding - skip
                // Could also throw an exception for strict mode
            }
        }

        // Pad to max length with nulls
        return str_pad($result, $maxBytes, "\x00");
    }

    /**
     * Get the default map category text for a game region
     *
     * @param string $region Game region code ('j' or 'e')
     * @return string Category text in the appropriate language
     */
    public static function getMapCategory(string $region = 'j'): string {
        return self::$mapCategories[$region] ?? self::$mapCategories['j'];
    }

    /**
     * Check if a map ID is an official map (from original game)
     *
     * @param int $mapId Map ID number
     * @return bool True if official map
     */
    public static function isOfficialMap(int $mapId): bool {
        return $mapId <= self::MAP_ID_OFFICIAL_MAX;
    }

    /**
     * Check if a map ID is a REON designer map
     *
     * @param int $mapId Map ID number
     * @return bool True if REON designer map
     */
    public static function isReonMap(int $mapId): bool {
        return $mapId >= self::MAP_ID_REON_MIN && $mapId <= self::MAP_ID_REON_MAX;
    }

    /**
     * Decode a map number from BCD format
     *
     * @param int $high High byte (hundreds)
     * @param int $low Low byte (units and tens)
     * @return int|null Decoded map number, or null if invalid/empty
     */
    public static function decodeMapNumber(int $high, int $low): ?int {
        if ($high === 0 && $low === 0) {
            return null; // User-made maps have no number
        }
        // BCD format: high byte is hundreds digit as hex, low byte is tens+units
        // e.g., 0x0A 0x03 = 10*100 + 03 = 1003
        return ($high * 100) + $low;
    }

    /**
     * Encode a map number to BCD format
     *
     * @param int|null $number Map number (0-9999), or null for user-made maps
     * @return array [high, low] bytes
     */
    public static function encodeMapNumber(?int $number): array {
        if ($number === null || $number <= 0) {
            return [0, 0];
        }
        $high = intdiv($number, 100); // Hundreds (as decimal value, stored as hex)
        $low = $number % 100;         // Tens and units
        return [$high, $low];
    }

    /**
     * Create map data for serving to a specific game region
     * Returns data from offset 0x02 onwards (header added dynamically on serve)
     *
     * Checksum formulas (corrected from Dan Docs):
     * - Size sum (0x02-0x03): Number of bytes from 0x20 to 0xFF terminator (inclusive)
     * - Data checksum (0x04): Sum of bytes from 0x20 to 0xFF terminator (inclusive), masked to 8 bits
     *
     * @param string $region Game region ('j' or 'e')
     * @param string $name Map name (max 8 bytes, should be in region's language)
     * @param int $width Map width (20-50)
     * @param int $height Map height (20-50)
     * @param array $tiles Array of tile bytes
     * @param array $units Array of unit data ['x' => int, 'y' => int, 'unit_id' => int]
     * @param int $playerGold Player starting gold (actual value, will be divided by 1000)
     * @param int $enemyGold Enemy starting gold (actual value, will be divided by 1000)
     * @param int $playerMaterials Player starting materials (actual value, will be divided by 10)
     * @param int $enemyMaterials Enemy starting materials (actual value, will be divided by 10)
     * @param int|null $mapNumber Map number (null for user-made maps)
     * @param string|null $category Category text (null uses default for region if map number is set)
     * @return string Binary map data without header
     */
    public static function createMapData(
        string $region,
        string $name,
        int $width,
        int $height,
        array $tiles,
        array $units = [],
        int $playerGold = 10000,
        int $enemyGold = 10000,
        int $playerMaterials = 100,
        int $enemyMaterials = 100,
        ?int $mapNumber = null,
        ?string $category = null
    ): string {
        if ($width < 20 || $width > 50 || $height < 20 || $height > 50) {
            throw new InvalidArgumentException("Map dimensions must be 20-50");
        }

        // Map name is 8 bytes, NOT 12
        if (strlen($name) > 8) {
            $name = substr($name, 0, 8);
        }
        $name = str_pad($name, 8, "\x00");

        // Build map data (starting from offset 0x02 - no header bytes)
        // Full file layout (with 2-byte header):
        //   0x00-0x01: Header (added on serve, not stored)
        //   0x02-0x03: Size sum
        //   0x04:      Data checksum
        //   0x05-0x0F: Zeros (11 bytes)
        //   0x10-0x18: Category text (9 bytes)
        //   0x19-0x1D: Zeros (5 bytes)
        //   0x1E-0x1F: Map number (BCD format)
        //   0x20-0x27: Map name (8 bytes)
        //   0x28:      Player Gold (in 1000s)
        //   0x29:      Enemy Gold (in 1000s)
        //   0x2A:      Player Materials (in 10s)
        //   0x2B:      Enemy Materials (in 10s)
        //   0x2C:      Width
        //   0x2D:      Height
        //   0x2E+:     Tile data, then units, then 0xFF terminator
        //
        // Stored data (without 2-byte header):
        //   0x00-0x01: Size sum
        //   0x02:      Data checksum
        //   0x03-0x0D: Zeros (11 bytes)
        //   0x0E-0x16: Category text (9 bytes)
        //   0x17-0x1B: Zeros (5 bytes)
        //   0x1C-0x1D: Map number (BCD format)
        //   0x1E-0x25: Map name (8 bytes)
        //   0x26:      Player Gold (in 1000s)
        //   0x27:      Enemy Gold (in 1000s)
        //   0x28:      Player Materials (in 10s)
        //   0x29:      Enemy Materials (in 10s)
        //   0x2A:      Width
        //   0x2B:      Height
        //   0x2C+:     Tile data

        // Encode map number to BCD
        [$mapNumHigh, $mapNumLow] = self::encodeMapNumber($mapNumber);

        // Get category text (default to region-appropriate "Official Map" if map number is set)
        if ($category === null && $mapNumber !== null) {
            $category = self::getMapCategory($region);
        }
        $categoryEncoded = $category !== null
            ? self::encodeMapName($category, 9)
            : str_repeat("\x00", 9);

        // Convert resource values to stored format
        $goldPlayerByte = min(255, intdiv($playerGold, 1000));
        $goldEnemyByte = min(255, intdiv($enemyGold, 1000));
        $matPlayerByte = min(255, intdiv($playerMaterials, 10));
        $matEnemyByte = min(255, intdiv($enemyMaterials, 10));

        $data = "\x00\x00"; // 0x00-0x01: Placeholder for size sum
        $data .= "\x00"; // 0x02: Placeholder for checksum
        $data .= str_repeat("\x00", 0x0B); // 0x03-0x0D: Zeros (11 bytes)
        $data .= $categoryEncoded; // 0x0E-0x16: Category text (9 bytes)
        $data .= str_repeat("\x00", 0x05); // 0x17-0x1B: Zeros (5 bytes)
        $data .= chr($mapNumHigh); // 0x1C: Map number high byte (BCD hundreds)
        $data .= chr($mapNumLow); // 0x1D: Map number low byte (BCD tens+units)
        $data .= $name; // 0x1E-0x25: Map name (8 bytes)
        $data .= chr($goldPlayerByte); // 0x26: Player Gold (in 1000s)
        $data .= chr($goldEnemyByte); // 0x27: Enemy Gold (in 1000s)
        $data .= chr($matPlayerByte); // 0x28: Player Materials (in 10s)
        $data .= chr($matEnemyByte); // 0x29: Enemy Materials (in 10s)
        $data .= chr($width); // 0x2A: Width
        $data .= chr($height); // 0x2B: Height

        // Add tiles
        foreach ($tiles as $tile) {
            $data .= chr($tile);
        }

        // Add units
        foreach ($units as $unit) {
            $data .= chr($unit['x']) . chr($unit['y']) . chr($unit['unit_id']);
        }

        // Add terminator
        $data .= "\xFF";

        // Calculate checksums
        // Data from 0x1E (name start in stored) to end (0xFF terminator)
        $dataStart = 0x1E; // Where checksummed data starts in our headerless format
        $ffPos = strlen($data) - 1; // Position of 0xFF terminator

        // Size sum: number of bytes from name start to terminator (inclusive)
        $sizeSum = $ffPos - $dataStart + 1;
        $data[0] = chr($sizeSum & 0xFF);
        $data[1] = chr(($sizeSum >> 8) & 0xFF);

        // Data checksum: sum of bytes from name start to terminator (inclusive)
        $sum = 0;
        for ($i = $dataStart; $i <= $ffPos; $i++) {
            $sum += ord($data[$i]);
        }
        $data[2] = chr($sum & 0xFF);

        return $data;
    }

    /**
     * Parse a map file (with or without header)
     *
     * File layout (with 2-byte header):
     *   0x00-0x01: Header
     *   0x02-0x03: Size sum
     *   0x04:      Data checksum
     *   0x05-0x0F: Zeros (11 bytes)
     *   0x10-0x18: Category text (9 bytes)
     *   0x19-0x1D: Zeros (5 bytes)
     *   0x1E-0x1F: Map number (BCD format)
     *   0x20-0x27: Map name (8 bytes, NOT 12!)
     *   0x28:      Player Gold (in 1000s)
     *   0x29:      Enemy Gold (in 1000s)
     *   0x2A:      Player Materials (in 10s)
     *   0x2B:      Enemy Materials (in 10s)
     *   0x2C:      Width
     *   0x2D:      Height
     *   0x2E+:     Tile data, then units, then 0xFF terminator
     *
     * @param string $data Raw map binary data
     * @param bool $hasHeader Whether the data includes the 2-byte header
     * @return array Parsed map data including category, map_number, and resources
     */
    public static function parseMapFile(string $data, bool $hasHeader = true): array {
        $offset = $hasHeader ? 0 : -2; // Adjust if no header

        // Parse category text (0x10-0x18 in full file, 0x0E-0x16 in headerless)
        $categoryRaw = substr($data, 0x10 + $offset, 9);
        $category = self::decodeMapName($categoryRaw);

        // Parse map number (0x1E-0x1F in full file, 0x1C-0x1D in headerless)
        $mapNumHigh = ord($data[0x1E + $offset]);
        $mapNumLow = ord($data[0x1F + $offset]);
        $mapNumber = self::decodeMapNumber($mapNumHigh, $mapNumLow);

        // Map name is 8 bytes (0x20-0x27), NOT 12
        $name = substr($data, 0x20 + $offset, 8);
        $name = rtrim($name, "\x00");

        // Parse starting resources (0x28-0x2B)
        // Gold values are stored in 1000s, Materials in 10s
        $playerGold = ord($data[0x28 + $offset]) * 1000;
        $enemyGold = ord($data[0x29 + $offset]) * 1000;
        $playerMaterials = ord($data[0x2A + $offset]) * 10;
        $enemyMaterials = ord($data[0x2B + $offset]) * 10;

        $width = ord($data[0x2C + $offset]);
        $height = ord($data[0x2D + $offset]);

        $tileCount = $width * $height;
        $tiles = [];
        for ($i = 0; $i < $tileCount; $i++) {
            $tiles[] = ord($data[0x2E + $offset + $i]);
        }

        // Parse units (everything after tiles until 0xFF)
        $units = [];
        $pos = 0x2E + $offset + $tileCount;
        while ($pos < strlen($data) - 1 && ord($data[$pos]) !== 0xFF) {
            $units[] = [
                'x' => ord($data[$pos]),
                'y' => ord($data[$pos + 1]),
                'unit_id' => ord($data[$pos + 2]),
            ];
            $pos += 3;
        }

        return [
            'name' => $name,
            'width' => $width,
            'height' => $height,
            'tiles' => $tiles,
            'units' => $units,
            'category' => $category ?: null,
            'map_number' => $mapNumber,
            'player_gold' => $playerGold,
            'enemy_gold' => $enemyGold,
            'player_materials' => $playerMaterials,
            'enemy_materials' => $enemyMaterials,
        ];
    }

    /**
     * Strip header from full map file for database storage
     */
    public static function stripMapHeader(string $fullMapData): string {
        return substr($fullMapData, 2);
    }

    /**
     * Validate map checksums (works with headerless data)
     *
     * Checksum formulas (corrected from Dan Docs):
     * - Size sum (0x02-0x03): Number of bytes from 0x20 to 0xFF terminator (inclusive)
     * - Data checksum (0x04): Sum of bytes from 0x20 to 0xFF terminator (inclusive), masked to 8 bits
     */
    public static function validateMap(string $data, bool $hasHeader = true): bool {
        $offset = $hasHeader ? 0 : -2;
        $checksumOffset = $hasHeader ? 2 : 0;

        if (strlen($data) < (0x2E + $offset)) {
            return false;
        }

        // Find 0xFF terminator (starts searching from map data area)
        $dataStart = 0x20 + $offset;
        $ffPos = null;
        for ($i = 0x2E + $offset; $i < strlen($data); $i++) {
            if (ord($data[$i]) === 0xFF) {
                $ffPos = $i;
                break;
            }
        }

        if ($ffPos === null) {
            return false; // No terminator found
        }

        // Validate size sum: number of bytes from 0x20 to 0xFF (inclusive)
        $expectedSize = $ffPos - $dataStart + 1;
        $actualSize = ord($data[$checksumOffset]) | (ord($data[$checksumOffset + 1]) << 8);

        if ($expectedSize !== $actualSize) {
            return false;
        }

        // Validate data checksum: sum of bytes from 0x20 to 0xFF (inclusive)
        $sum = 0;
        for ($i = $dataStart; $i <= $ffPos; $i++) {
            $sum += ord($data[$i]);
        }
        $expectedChecksum = $sum & 0xFF;
        $actualChecksum = ord($data[$checksumOffset + 2]);

        return $expectedChecksum === $actualChecksum;
    }

    /**
     * Import existing binary map file to database format
     * Strips header and returns data ready for storage
     */
    public static function importMapFile(string $filePath): array {
        $fullData = file_get_contents($filePath);
        if ($fullData === false) {
            throw new RuntimeException("Could not read file: $filePath");
        }

        $parsed = self::parseMapFile($fullData, true);
        $dataWithoutHeader = self::stripMapHeader($fullData);

        return [
            'map_name' => $parsed['name'],
            'width' => $parsed['width'],
            'height' => $parsed['height'],
            'map_data' => $dataWithoutHeader,
        ];
    }

    /**
     * Create a properly formatted mbox message for storage
     *
     * Format:
     * - Line 1: "BD=xx" control header (xx = 2 uppercase hex digits)
     * - Line 2: Title (will be truncated to 9 chars by game)
     * - Line 3+: Message body
     * - All lines terminated with CR/LF
     * - Encoding: Shift JIS
     *
     * @param string $title Message title (max 9 chars displayed)
     * @param string $body Message body (UTF-8, will be converted to Shift JIS)
     * @param int $bdValue Optional BD header value (0x00-0xFF)
     * @return string Properly formatted message data in Shift JIS
     */
    public static function createMboxMessage(string $title, string $body, int $bdValue = 0): string {
        $crlf = "\r\n";

        // Build header line
        $header = sprintf("BD=%02X", $bdValue & 0xFF);

        // Convert body lines to use CRLF termination
        $bodyLines = preg_split('/\r?\n/', $body);
        $formattedBody = implode($crlf, $bodyLines);

        // Assemble message
        $message = $header . $crlf . $title . $crlf . $formattedBody . $crlf;

        // Convert to Shift JIS if needed (assuming input is UTF-8)
        if (function_exists('mb_convert_encoding')) {
            $message = mb_convert_encoding($message, 'SJIS', 'UTF-8');
        }

        return $message;
    }

    /**
     * Create mbox message using legacy 7-space format (for compatibility with existing files)
     *
     * @param string $title Message title
     * @param string $body Message body (UTF-8)
     * @return string Properly formatted message data
     */
    public static function createMboxMessageLegacy(string $title, string $body): string {
        $crlf = "\r\n";

        // 7 spaces as first line (legacy format)
        $header = "       ";

        // Convert body lines to use CRLF termination
        $bodyLines = preg_split('/\r?\n/', $body);
        $formattedBody = implode($crlf, $bodyLines);

        // Assemble message: 7 spaces + title on same line, then body
        $message = $header . $title . $crlf . $crlf . $formattedBody . $crlf;

        // Convert to Shift JIS if needed (assuming input is UTF-8)
        if (function_exists('mb_convert_encoding')) {
            $message = mb_convert_encoding($message, 'SJIS', 'UTF-8');
        }

        return $message;
    }

    /**
     * Validate mbox message format
     * Checks for proper CRLF termination and BD=xx or 7-space header
     */
    public static function validateMboxMessage(string $data): array {
        $errors = [];

        // Check for CRLF line endings
        if (strpos($data, "\r\n") === false) {
            $errors[] = 'Message must use CRLF (\\r\\n) line termination';
        }

        // Check for proper line termination (not just delimiting)
        if (!str_ends_with($data, "\r\n")) {
            $errors[] = 'Message must end with CRLF';
        }

        // Check for BD=xx header or 7-space legacy format on first line
        $firstChars = substr($data, 0, 7);
        $hasBdHeader = preg_match('/^BD=[0-9A-F]{2}/', $data);
        $hasLegacyHeader = $firstChars === "       "; // 7 spaces

        if (!$hasBdHeader && !$hasLegacyHeader) {
            $errors[] = 'First line must be BD=XX (2 uppercase hex digits) or 7 spaces';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'format' => $hasBdHeader ? 'bd' : ($hasLegacyHeader ? 'legacy' : 'unknown'),
        ];
    }

    /**
     * Generate mbox_serial.txt content from database
     *
     * @param array $serials Array of [mailbox_id => serial_number] for active messages
     * @return string Serial file content (16 lines, LF terminated)
     */
    public static function generateSerialFile(array $serials): string {
        $lines = [];
        for ($i = 0; $i < 16; $i++) {
            $lines[] = $serials[$i] ?? '0000';
        }
        return implode("\n", $lines);
    }

    // ---- Admin: custom maps (bww_maps) ----------------------------------
    //
    // Added 2026-09-28 for the admin panel. Everything above this point
    // (header bytes, checksum math, parsing) already existed and is used
    // by the live routes (map.php, map_menu.php); these methods are the
    // panel's own read/write layer on top of it, the same split
    // StadiumUtil keeps between "how the format works" and "how the
    // panel manages it".

    // For the panel's list: every map, newest id first within each
    // range (official maps sort together, then REON ones).
    public static function listMaps() {
        $db = DBUtil::getInstance()->getDB();
        $stmt = $db->prepare(
            "select id, map_id, map_name_j, map_name_e, category_j, category_e,
                    width, height, price_yen, download_count, is_active, timestamp,
                    length(map_data) as data_size
               from bww_maps
              order by map_id asc");
        $stmt->execute();
        return DBUtil::fancy_get_result($stmt);
    }

    // The next free id in the REON range (2000-9999). Never reuses a
    // retired id within a session -- always the current max + 1 -- so a
    // deactivated (but not deleted) map's id is never handed to a
    // different map later, the same "never reuse a File ID" reasoning
    // Mobile Stadium's spec gives for its own identifiers.
    public static function nextReonMapId() {
        $db = DBUtil::getInstance()->getDB();
        $row = $db->query(
            "select max(cast(map_id as unsigned)) as m from bww_maps
              where cast(map_id as unsigned) >= " . self::MAP_ID_REON_MIN . "
                and cast(map_id as unsigned) <= " . self::MAP_ID_REON_MAX
        )->fetch_assoc();
        $max = $row["m"] !== null ? (int)$row["m"] : (self::MAP_ID_REON_MIN - 1);
        $next = $max + 1;
        if ($next > self::MAP_ID_REON_MAX) return null; // range exhausted
        return $next;
    }

    // Validates and imports a full map file (WITH its 2-byte header, the
    // same shape as the seeder's own input files and what a map editor
    // would export) as a new REON map. Assigns the next free REON id --
    // an admin never types one by hand, the same way Mobile Stadium's
    // File ID is generated, not entered. Returns [id, ""] or [null, reason].
    public static function importMap($fullFileData, $priceYen, $mapNameE = null, $categoryE = null) {
        if (!is_string($fullFileData) || strlen($fullFileData) < 0x2E) {
            return [null, "file is too short to be a map"];
        }
        if (!self::validateMap($fullFileData, true)) {
            return [null, "checksum in the file does not match its own contents -- not a valid map file"];
        }
        try {
            $parsed = self::parseMapFile($fullFileData, true);
        } catch (\Throwable $e) {
            return [null, "could not parse the map: " . $e->getMessage()];
        }
        if ($parsed["width"] < 20 || $parsed["width"] > 50 || $parsed["height"] < 20 || $parsed["height"] > 50) {
            return [null, "map dimensions ({$parsed['width']}x{$parsed['height']}) must be 20-50"];
        }
        if ($priceYen !== null && (!is_int($priceYen) || $priceYen < 0 || $priceYen > 9999)) {
            return [null, "price must be null or an integer from 0 to 9999 yen"];
        }

        $mapId = self::nextReonMapId();
        if ($mapId === null) return [null, "no free map id left in the REON range (2000-9999)"];
        $mapIdStr = str_pad((string)$mapId, 4, "0", STR_PAD_LEFT);

        $nameJ = self::decodeMapName(substr($fullFileData, 0x20, 8));
        if ($nameJ === "") $nameJ = "Map $mapIdStr";
        $categoryJ = $parsed["category"] ?: self::getMapCategory("j");
        $mapData = self::stripMapHeader($fullFileData);

        $db = DBUtil::getInstance()->getDB();
        $stmt = $db->prepare(
            "insert into bww_maps
               (map_id, map_name, map_name_j, map_name_e, category_j, category_e,
                width, height, price_yen, map_data, is_active)
             values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
        // 's' for map_data (binary), not 'b' -- the same mysqli pitfall
        // StadiumUtil::storeOne() documents: 'b' silently writes 0 bytes
        // without an explicit send_long_data() call.
        $priceOrDefault = $priceYen ?? 10;
        $stmt->bind_param("ssssssiiis", $mapIdStr, $nameJ, $nameJ, $mapNameE, $categoryJ, $categoryE,
            $parsed["width"], $parsed["height"], $priceOrDefault, $mapData);
        try {
            $stmt->execute();
        } catch (\mysqli_sql_exception $e) {
            return [null, "database error: " . $e->getMessage()];
        }
        return [$db->insert_id, ""];
    }

    // Price, English name and English category are the only fields an
    // admin edits after import -- everything else (the map's actual
    // layout, tiles, units) comes from the file, and editing it here
    // would silently diverge the DB from what the checksum in map_data
    // actually covers.
    public static function updateMapMeta($id, $priceYen, $mapNameE, $categoryE) {
        if ($priceYen !== null && (!is_int($priceYen) || $priceYen < 0 || $priceYen > 9999)) {
            return "price must be null or an integer from 0 to 9999 yen";
        }
        $db = DBUtil::getInstance()->getDB();
        $id = (int)$id;
        $priceOrDefault = $priceYen ?? 10;
        $stmt = $db->prepare("update bww_maps set price_yen = ?, map_name_e = ?, category_e = ? where id = ?");
        $stmt->bind_param("issi", $priceOrDefault, $mapNameE, $categoryE, $id);
        try {
            $stmt->execute();
        } catch (\mysqli_sql_exception $e) {
            return "database error: " . $e->getMessage();
        }
        return "";
    }

    public static function setMapActive($id, $active) {
        try {
            $db = DBUtil::getInstance()->getDB();
            $stmt = $db->prepare("update bww_maps set is_active = ? where id = ?");
            $a = $active ? 1 : 0;
            $id = (int)$id;
            $stmt->bind_param("ii", $a, $id);
            return $stmt->execute();
        } catch (\mysqli_sql_exception $e) {
            error_log("GameboyWars3Util::setMapActive($id) failed: " . $e->getMessage());
            return false;
        }
    }

    // ---- Admin: mailbox messages (bww_messages) --------------------------
    //
    // Called "News" inside the game itself (gbwars3-en disassembly,
    // data/news.asm: `News_Menu_Message_Service`, coord_text "MESSAGES",
    // the delete-confirmation prompt "このメッセージを さくじょしますか?"
    // -- "Delete this message?"). REON's own "mbox" naming (mbox.php,
    // bww_messages) is this server's label for the same feature, not the
    // game's. Confirmed 2026-09-28 against github.com/REONTeam/gbwars3-en.
    //
    // ENCODING WARNING, not resolved, read before changing this: mbox.php's
    // own doc comment claims Shift-JIS, and createMboxMessage() above
    // converts to SJIS -- but every message actually live in production
    // right now does NOT match that. Checked directly: mailbox e/0's
    // stored bytes for "！" are EF BC 81, which is UTF-8 for U+FF01: a
    // Shift-JIS encoder would have written 81 49. The disassembly's own
    // "News" charmap (charmaps/char_news.inc) doesn't match either --
    // there 'W' is $27, not the $57 every live message actually stores.
    // So the real, already-serving data is plain UTF-8/ASCII bytes under
    // a 7-space legacy header, not SJIS and not that charmap. These
    // methods match the REAL data instead of trusting the unverified
    // SJIS assumption above -- but nothing here has been confirmed on a
    // console either. If a newly composed message renders wrong in
    // game, this paragraph is the first thing to revisit.
    const MAILBOX_MIN = 0;
    const MAILBOX_MAX = 15;

    private static function buildMessageData($title, $body) {
        $crlf = "\r\n";
        $header = str_repeat(" ", 7); // legacy format -- see the encoding warning above
        $bodyLines = preg_split('/\r?\n/', (string)$body);
        $formattedBody = implode($crlf, $bodyLines);
        return $header . $title . $crlf . $crlf . $formattedBody . $crlf;
    }

    // Reverses buildMessageData() (and reads existing legacy-header
    // messages the same way) for pre-filling the edit form. Falls back
    // to a best-effort split for anything with a BD=xx header instead --
    // none exist in production today, but a future message created
    // through some other path could still have one.
    public static function decodeMessage($data) {
        $legacyHeader = str_repeat(" ", 7);
        if (substr($data, 0, 7) === $legacyHeader) {
            $rest = substr($data, 7);
            $parts = explode("\r\n\r\n", $rest, 2);
            $title = $parts[0] ?? "";
            $body = isset($parts[1]) ? rtrim(str_replace("\r\n", "\n", $parts[1]), "\n") : "";
            return [$title, $body];
        }
        $lines = explode("\r\n", $data, 3);
        $title = $lines[1] ?? "";
        $body = isset($lines[2]) ? rtrim(str_replace("\r\n", "\n", $lines[2]), "\n") : "";
        return [$title, $body];
    }

    public static function listMessages() {
        $db = DBUtil::getInstance()->getDB();
        $stmt = $db->prepare(
            "select id, game_region, mailbox_id, serial_number, subject, is_active, timestamp
               from bww_messages
              order by game_region asc, mailbox_id asc");
        $stmt->execute();
        return DBUtil::fancy_get_result($stmt);
    }

    public static function getMessage($region, $mailboxId) {
        $db = DBUtil::getInstance()->getDB();
        $mailboxId = (int)$mailboxId;
        $stmt = $db->prepare("select * from bww_messages where game_region = ? and mailbox_id = ?");
        $stmt->bind_param("si", $region, $mailboxId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    // Creates or overwrites the message in one (region, mailbox) slot.
    // The serial number always changes on a write -- mbox_serial.txt is
    // how the game decides which mailboxes to re-download (its own doc
    // comment: "only downloads mailboxes with changed serial numbers"),
    // so leaving it the same on an edit would mean consoles that already
    // have the old text never see the new one, the exact bug class
    // Mobile Stadium's File ID exists to prevent. Returns "" or the
    // reason for the refusal.
    public static function setMessage($region, $mailboxId, $title, $body, $isActive) {
        $region = strtolower((string)$region);
        if ($region !== "j" && $region !== "e") return "region must be 'j' or 'e'";
        $mailboxId = (int)$mailboxId;
        if ($mailboxId < self::MAILBOX_MIN || $mailboxId > self::MAILBOX_MAX) {
            return "mailbox id must be " . self::MAILBOX_MIN . "-" . self::MAILBOX_MAX;
        }
        $title = trim((string)$title);
        if ($title === "") return "title must not be empty";

        $db = DBUtil::getInstance()->getDB();
        $existing = self::getMessage($region, $mailboxId);
        $currentSerial = $existing !== null ? (int)$existing["serial_number"] : 0;
        $nextSerial = $currentSerial + 1;
        if ($nextSerial > 9999) $nextSerial = 1; // char(4); wrap rather than overflow
        $serialStr = str_pad((string)$nextSerial, 4, "0", STR_PAD_LEFT);

        $messageData = self::buildMessageData($title, $body);
        $isActiveInt = $isActive ? 1 : 0;

        $stmt = $db->prepare(
            "insert into bww_messages (game_region, mailbox_id, serial_number, subject, message_data, is_active)
             values (?, ?, ?, ?, ?, ?)
             on duplicate key update
               serial_number = values(serial_number),
               subject = values(subject),
               message_data = values(message_data),
               is_active = values(is_active)");
        $stmt->bind_param("sisssi", $region, $mailboxId, $serialStr, $title, $messageData, $isActiveInt);
        try {
            $stmt->execute();
        } catch (\mysqli_sql_exception $e) {
            return "database error: " . $e->getMessage();
        }
        return "";
    }

    public static function setMessageActive($region, $mailboxId, $active) {
        try {
            $db = DBUtil::getInstance()->getDB();
            $mailboxId = (int)$mailboxId;
            $a = $active ? 1 : 0;
            $stmt = $db->prepare("update bww_messages set is_active = ? where game_region = ? and mailbox_id = ?");
            $stmt->bind_param("isi", $a, $region, $mailboxId);
            return $stmt->execute();
        } catch (\mysqli_sql_exception $e) {
            error_log("GameboyWars3Util::setMessageActive($region, $mailboxId) failed: " . $e->getMessage());
            return false;
        }
    }

    public static function deleteMessage($region, $mailboxId) {
        try {
            $db = DBUtil::getInstance()->getDB();
            $mailboxId = (int)$mailboxId;
            $stmt = $db->prepare("delete from bww_messages where game_region = ? and mailbox_id = ?");
            $stmt->bind_param("si", $region, $mailboxId);
            return $stmt->execute();
        } catch (\mysqli_sql_exception $e) {
            error_log("GameboyWars3Util::deleteMessage($region, $mailboxId) failed: " . $e->getMessage());
            return false;
        }
    }

    // ---- Admin: mercenary prices (bww_mercenary_prices) -----------------
    //
    // youhei_menu.php shipped with 5 prices hardcoded in the route file
    // itself, with no database table and no admin control at all --
    // unlike maps and messages, which already had one before this
    // session. getPrices() falls back to those SAME hardcoded defaults
    // on a database error or an empty table, so a broken read never
    // breaks the game: the mercenary menu simply serves what it always
    // served.
    const MERCENARY_DEFAULT_PRICES = [30, 50, 50, 50, 50];

    public static function getMercenaryPrices() {
        try {
            $db = DBUtil::getInstance()->getDB();
            $result = $db->query("select unit_index, price_yen from bww_mercenary_prices order by unit_index asc");
            $prices = self::MERCENARY_DEFAULT_PRICES;
            while ($row = $result->fetch_assoc()) {
                $i = (int)$row["unit_index"];
                if ($i >= 0 && $i < count($prices)) $prices[$i] = (int)$row["price_yen"];
            }
            return $prices;
        } catch (\mysqli_sql_exception $e) {
            error_log("GameboyWars3Util::getMercenaryPrices() failed, serving hardcoded defaults: " . $e->getMessage());
            return self::MERCENARY_DEFAULT_PRICES;
        }
    }

    // Replaces all 5 prices at once -- the form always submits all 5, so
    // there is no partial-update case to support. Returns "" or the
    // reason for the refusal.
    public static function setMercenaryPrices(array $prices) {
        if (count($prices) !== 5) return "expected exactly 5 prices";
        foreach ($prices as $i => $p) {
            if (!is_int($p) || $p < 0 || $p > 9999) {
                return "price for unit $i must be an integer from 0 to 9999 yen";
            }
        }
        $db = DBUtil::getInstance()->getDB();
        try {
            $stmt = $db->prepare(
                "insert into bww_mercenary_prices (unit_index, price_yen) values (?, ?)
                 on duplicate key update price_yen = values(price_yen)");
            foreach ($prices as $i => $p) {
                $stmt->bind_param("ii", $i, $p);
                $stmt->execute();
            }
        } catch (\mysqli_sql_exception $e) {
            return "database error: " . $e->getMessage();
        }
        return "";
    }
}
