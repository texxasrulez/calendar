<?php

class rounddav_utils
{
    /**
     * Pick a deterministic color from a palette based on name.
     *
     * @param string $name
     * @param array  $palette List of hex colors (with or without leading #)
     *
     * @return string|null Hex color without leading #
     */
    public static function pick_color_from_name($name, $palette)
    {
        if (empty($palette) || !is_array($palette)) {
            return null;
        }

        $name = (string) $name;
        $hash = (int) sprintf('%u', crc32($name));
        $idx  = $hash % count($palette);
        $color = (string) $palette[$idx];

        $color = ltrim($color, '#');
        $color = strtolower($color);

        return $color !== '' ? $color : null;
    }
}
