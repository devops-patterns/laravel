<?php

namespace App\Support;

class Version
{
    /**
     * Версия из локального git или $fallback, если репозитория нет. Нужна только
     * локально: в собранном образе APP_VERSION задан и этот фолбэк не вызывается.
     * Возвращаем полный sha (фронт режет до короткого) + суффикс "-dirty", если
     * в рабочем дереве есть незакоммиченные изменения (по конвенции git — только
     * отслеживаемые файлы).
     */
    public static function gitSha(string $fallback = 'dev'): string
    {
        $sha = self::readHead();
        if ($sha === null) {
            return $fallback;
        }

        return self::isDirty() ? $sha.'-dirty' : $sha;
    }

    /**
     * Полный sha текущего HEAD, читая .git без shell. null, если не определить.
     */
    private static function readHead(): ?string
    {
        $git = base_path('.git');

        $head = $git.'/HEAD';
        if (! is_file($head)) {
            return null;
        }

        $ref = trim((string) file_get_contents($head));

        // Отсоединённый HEAD: в файле сразу sha.
        if (! str_starts_with($ref, 'ref: ')) {
            return $ref !== '' ? $ref : null;
        }

        $refName = substr($ref, 5); // refs/heads/master

        // Обычная ветка: sha лежит в .git/<ref>.
        $refFile = $git.'/'.$refName;
        if (is_file($refFile)) {
            $sha = trim((string) file_get_contents($refFile));

            return $sha !== '' ? $sha : null;
        }

        // Упакованные ссылки: .git/packed-refs.
        $packed = $git.'/packed-refs';
        if (is_file($packed)) {
            foreach (file($packed, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                    continue;
                }
                $parts = explode(' ', $line, 2);
                if (isset($parts[1]) && $parts[1] === $refName) {
                    return $parts[0];
                }
            }
        }

        return null;
    }

    /**
     * Есть ли незакоммиченные изменения отслеживаемых файлов (best-effort).
     * git diff-index: код возврата 0 — чисто, 1 — грязно. Если exec недоступен
     * или git не отвечает — считаем чистым (без суффикса).
     */
    private static function isDirty(): bool
    {
        if (! function_exists('exec')) {
            return false;
        }

        $code = 0;
        @exec('git -C '.escapeshellarg(base_path()).' diff-index --quiet HEAD -- 2>/dev/null', $out, $code);

        return $code === 1;
    }
}
