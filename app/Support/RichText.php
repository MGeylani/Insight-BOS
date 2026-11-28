<?php

namespace App\Support;

/**
 * Rich text is stored as a ProseMirror / Tiptap JSON document:
 *   {"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"..."}]}]}
 * These helpers build a document from plain text, read it back as plain text (for search and
 * notifications), list the people mentioned in it and drop anything unexpected.
 */
class RichText
{
    private const NODES = [
        'doc', 'paragraph', 'text', 'heading', 'bulletList', 'orderedList', 'listItem', 'blockquote',
        'codeBlock', 'hardBreak', 'horizontalRule', 'mention', 'image', 'taskList', 'taskItem',
    ];

    private const MARKS = ['bold', 'italic', 'strike', 'underline', 'code', 'link'];

    /** @return array<string, mixed>|null */
    public static function fromPlain(?string $text): ?array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }
        $paragraphs = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            $paragraphs[] = $line === ''
                ? ['type' => 'paragraph']
                : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $line]]];
        }

        return ['type' => 'doc', 'content' => $paragraphs];
    }

    /** Accept a document (array or JSON string) or plain text; return a clean document or null when empty. */
    public static function normalize(mixed $value): ?array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) && ($decoded['type'] ?? null) === 'doc' ? $decoded : self::fromPlain($value);
        }
        if (! is_array($value) || ($value['type'] ?? null) !== 'doc') {
            return null;
        }
        $clean = self::clean($value);

        return trim(self::toPlain($clean)) === '' && ! self::hasImage($clean) ? null : $clean;
    }

    public static function toPlain(?array $doc): string
    {
        if (! $doc) {
            return '';
        }
        $out = '';
        self::walk($doc, function (array $node) use (&$out) {
            match ($node['type'] ?? null) {
                'text' => $out .= $node['text'] ?? '',
                'mention' => $out .= '@'.($node['attrs']['label'] ?? ''),
                'hardBreak' => $out .= "\n",
                default => null,
            };
        }, function (array $node) use (&$out) {
            if (in_array($node['type'] ?? null, ['paragraph', 'heading', 'listItem', 'codeBlock', 'blockquote', 'taskItem'], true)) {
                $out = rtrim($out, "\n")."\n";
            }
        });

        return trim(preg_replace("/\n{3,}/", "\n\n", $out));
    }

    /** @return list<int> user ids of @mentions */
    public static function mentions(?array $doc): array
    {
        $ids = [];
        if ($doc) {
            self::walk($doc, function (array $node) use (&$ids) {
                if (($node['type'] ?? null) === 'mention' && is_numeric($node['attrs']['id'] ?? null)) {
                    $ids[] = (int) $node['attrs']['id'];
                }
            });
        }

        return array_values(array_unique($ids));
    }

    /** @return list<int> attachment ids of pasted images */
    public static function images(?array $doc): array
    {
        $ids = [];
        if ($doc) {
            self::walk($doc, function (array $node) use (&$ids) {
                if (($node['type'] ?? null) === 'image' && preg_match('#/attachments/(\d+)/#', (string) ($node['attrs']['src'] ?? ''), $m)) {
                    $ids[] = (int) $m[1];
                }
            });
        }

        return array_values(array_unique($ids));
    }

    public static function excerpt(?array $doc, int $length = 140): string
    {
        $text = preg_replace('/\s+/u', ' ', self::toPlain($doc));

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1).'…' : $text;
    }

    private static function hasImage(array $doc): bool
    {
        $found = false;
        self::walk($doc, function (array $node) use (&$found) {
            $found = $found || ($node['type'] ?? null) === 'image';
        });

        return $found;
    }

    /** Keep known nodes and marks only; images must point at BOS attachments, links at http(s). */
    private static function clean(array $node): ?array
    {
        $type = $node['type'] ?? null;
        if (! in_array($type, self::NODES, true)) {
            return null;
        }
        $out = ['type' => $type];
        if ($type === 'text') {
            $out['text'] = mb_substr((string) ($node['text'] ?? ''), 0, 20000);
            $marks = [];
            foreach ((array) ($node['marks'] ?? []) as $mark) {
                if (! in_array($mark['type'] ?? null, self::MARKS, true)) {
                    continue;
                }
                if ($mark['type'] === 'link') {
                    $href = (string) ($mark['attrs']['href'] ?? '');
                    if (! preg_match('#^(https?://|mailto:|/)#i', $href)) {
                        continue;
                    }
                    $marks[] = ['type' => 'link', 'attrs' => ['href' => $href]];
                } else {
                    $marks[] = ['type' => $mark['type']];
                }
            }
            if ($marks) {
                $out['marks'] = $marks;
            }

            return $out['text'] === '' ? null : $out;
        }
        $attrs = (array) ($node['attrs'] ?? []);
        $keep = match ($type) {
            'heading' => ['level' => max(1, min(3, (int) ($attrs['level'] ?? 2)))],
            'orderedList' => ['start' => (int) ($attrs['start'] ?? 1)],
            'taskItem' => ['checked' => (bool) ($attrs['checked'] ?? false)],
            'mention' => ['id' => (string) ($attrs['id'] ?? ''), 'label' => mb_substr((string) ($attrs['label'] ?? ''), 0, 120)],
            'image' => preg_match('#^/attachments/\d+/inline$#', (string) ($attrs['src'] ?? ''))
                ? ['src' => (string) $attrs['src'], 'alt' => mb_substr((string) ($attrs['alt'] ?? ''), 0, 190)] : null,
            'codeBlock' => ['language' => null],
            default => [],
        };
        if ($keep === null) {
            return null;
        }
        if ($keep) {
            $out['attrs'] = $keep;
        }
        if (isset($node['content']) && is_array($node['content'])) {
            $children = array_values(array_filter(array_map(fn ($c) => is_array($c) ? self::clean($c) : null, $node['content'])));
            if ($children) {
                $out['content'] = $children;
            }
        }

        return $out;
    }

    private static function walk(array $node, callable $enter, ?callable $leave = null): void
    {
        $enter($node);
        foreach ((array) ($node['content'] ?? []) as $child) {
            if (is_array($child)) {
                self::walk($child, $enter, $leave);
            }
        }
        $leave && $leave($node);
    }
}
