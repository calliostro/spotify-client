<?php

declare(strict_types=1);

return [
    'baseUrl' => 'https://api.spotify.com/v1/',
    'accountUrl' => 'https://accounts.spotify.com/',
    'client' => [
        'class' => 'GuzzleHttp\Client',
        'options' => [
            'base_uri' => 'https://api.spotify.com/v1/',
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'SpotifyClient/1.0.0 +https://github.com/calliostro/spotify-client',
                'Accept' => 'application/json',
            ],
        ],
    ],
];
