<?php

namespace App\Support;

class EmailBodyFormatter
{
    public static function linkifyUrls(string $html): string
    {
        $parts = preg_split('/(<a\b[^>]*>.*?<\/a>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return $html;
        }

        $result = '';

        foreach ($parts as $part) {
            if ($part !== '' && preg_match('/^<a\b/i', $part)) {
                $result .= $part;

                continue;
            }

            $result .= preg_replace_callback(
                '/(?<![="\'])(https?:\/\/[^\s<>"\'\]]+)/i',
                static function (array $matches): string {
                    $url = $matches[1];
                    $escapedUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

                    return '<a href="'.$escapedUrl.'" target="_blank" rel="noopener noreferrer" style="color:#00529B;text-decoration:underline;">'.$escapedUrl.'</a>';
                },
                $part
            );
        }

        return $result;
    }
}
