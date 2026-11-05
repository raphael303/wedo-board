<?php
declare(strict_types=1);

/**
 * Fantasienamen: deterministisch aus Browser-Schlüssel und Board-Schlüssel,
 * damit eine Person auf einem Board immer gleich heisst, auf einem anderen aber anders.
 */
function wb_name_parts(string $lang): array {
    if ($lang === 'en') {
        return [
            ['Brave', 'Quiet', 'Curious', 'Swift', 'Gentle', 'Clever', 'Bright', 'Calm', 'Bold', 'Wise',
             'Merry', 'Keen', 'Nimble', 'Patient', 'Sunny', 'Witty', 'Steady', 'Lively', 'Humble', 'Daring',
             'Cheerful', 'Thoughtful', 'Sharp', 'Eager', 'Jolly', 'Mellow', 'Proud', 'Rapid', 'Sly', 'Tiny'],
            ['Otter', 'Fox', 'Owl', 'Lynx', 'Heron', 'Badger', 'Falcon', 'Hare', 'Seal', 'Robin',
             'Beaver', 'Marten', 'Ibex', 'Swan', 'Wolf', 'Deer', 'Finch', 'Trout', 'Hedgehog', 'Crane',
             'Dolphin', 'Panda', 'Koala', 'Gecko', 'Moose', 'Puffin', 'Raven', 'Salmon', 'Tortoise', 'Yak'],
        ];
    }
    return [
        ['Mutiger', 'Stiller', 'Neugieriger', 'Flinker', 'Sanfter', 'Kluger', 'Heller', 'Ruhiger', 'Kecker', 'Weiser',
         'Fröhlicher', 'Wacher', 'Wendiger', 'Geduldiger', 'Sonniger', 'Witziger', 'Standhafter', 'Munterer', 'Bescheidener', 'Kühner',
         'Heiterer', 'Nachdenklicher', 'Scharfer', 'Eifriger', 'Lustiger', 'Gelassener', 'Stolzer', 'Rascher', 'Schlauer', 'Winziger'],
        ['Otter', 'Fuchs', 'Uhu', 'Luchs', 'Reiher', 'Dachs', 'Falke', 'Hase', 'Seehund', 'Rotkehlchen',
         'Biber', 'Marder', 'Steinbock', 'Schwan', 'Wolf', 'Hirsch', 'Fink', 'Forelle', 'Igel', 'Kranich',
         'Delfin', 'Panda', 'Koala', 'Gecko', 'Elch', 'Papageitaucher', 'Rabe', 'Lachs', 'Schildkröte', 'Yak'],
    ];
}

function wb_fantasy_name(string $uid, string $boardKey, string $lang): string {
    [$adj, $animals] = wb_name_parts($lang);
    $h = crc32($uid . '|' . $boardKey);
    $a = $adj[$h % count($adj)];
    $n = $animals[intdiv($h, 97) % count($animals)];
    return $a . ' ' . $n;
}
