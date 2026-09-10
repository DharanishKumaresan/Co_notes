<?php
declare(strict_types=1);

namespace CoNotes;

/**
 * The theme catalogue.
 *
 * A theme swaps the accent token set only - the matte dark ground, the type,
 * the spacing and the layout are the same in every one. The actual colour
 * values live in app.css under `:root[data-theme="..."]`; this list exists so
 * the server can reject ids it does not know.
 *
 * Keep in step with THEMES in public_html/assets/app.js.
 */
final class Themes
{
    public const DEFAULT = 'lime';

    /** @var array<string,string> id => human label */
    public const ALL = [
        'lime'   => 'Lime',
        'amber'  => 'Amber',
        'teal'   => 'Teal',
        'sky'    => 'Sky',
        'violet' => 'Violet',
        'rose'   => 'Rose',
    ];

    public static function isValid(string $id): bool
    {
        return array_key_exists($id, self::ALL);
    }

    /**
     * Validate a theme id. `null` is meaningful on a board profile - it means
     * "inherit whatever my master profile says" - so it is passed through.
     */
    public static function normalise(mixed $v, bool $allowNull = false): ?string
    {
        if ($v === null || $v === '') {
            if ($allowNull) {
                return null;
            }
            return self::DEFAULT;
        }

        if (!is_string($v) || !self::isValid($v)) {
            throw ApiError::badRequest('Unknown theme.', 'invalid_theme');
        }

        return $v;
    }

    /** @return list<array{id:string,label:string}> */
    public static function catalogue(): array
    {
        $out = [];
        foreach (self::ALL as $id => $label) {
            $out[] = ['id' => $id, 'label' => $label];
        }
        return $out;
    }
}
