<?php

namespace App\Classes;

/**
 * A thumbnail's hand framing (Ketura, 2026-11-07; shared 2026-11-09): what the crop editor
 * (app.thumbnail.js) sends, {code, w, l, t} as the picture's CSS width and offsets in percent
 * of the tile, checked before it's kept.
 */
final class ThumbnailCrop
{
    /**
     * The framing, if it's for one of the cards it may frame and keeps the picture at least as
     * wide as the tile and covering it (the editor's smallest box is an eighth of the picture);
     * anything else is null
     * @param string[] $codes the cards it may be for
     */
    public static function sanitize(mixed $crop, array $codes): ?array
    {
        if (is_string($crop)) {
            $crop = json_decode($crop, true);
        }
        if (!is_array($crop) || !is_string($crop['code'] ?? null) || !in_array($crop['code'], $codes, true)) {
            return null;
        }
        $w = $crop['w'] ?? null;
        $l = $crop['l'] ?? null;
        $t = $crop['t'] ?? null;
        if (!is_numeric($w) || !is_numeric($l) || !is_numeric($t)) {
            return null;
        }
        [$w, $l, $t] = [round((float) $w, 2), round((float) $l, 2), round((float) $t, 2)];
        if ($w < 100 || $w > 2000 || $l > 0 || $l < 100 - $w || $t > 0 || $t < -2 * $w) {
            return null;
        }
        return ['code' => $crop['code'], 'w' => $w, 'l' => $l, 't' => $t];
    }
}
