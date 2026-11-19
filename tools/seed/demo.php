<?php
// Satz «demo»: vorzeigbare, erfundene Unterrichtsinhalte (Stand U0, ohne Organisationen).
// Ab U4 erhält der Vorführbezirk hier seine Schulen, Klassen und Konten.
return [
    'ai' => true,
    'boards' => [
        [
            'name' => 'Klimawandel: Ursachen und Folgen', 'type' => 'columns', 'columns' => ['Ursachen', 'Folgen', 'Was wir tun können'], 'background' => 'meer',
            'note' => 'Vorführung · Geografie 2. Sek · Klimawandel · Einstieg', 'tags' => 'vorführung, geografie, klima',
            'posts' => [
                ['col' => 0, 'title' => 'Verbrennung', 'body' => 'Kohle, Öl und Gas setzen CO₂ frei.', 'who' => 's1', 'reactions' => ['👍', '👍']],
                ['col' => 0, 'body' => 'Viele Flugreisen.', 'who' => 's2'],
                ['col' => 0, 'body' => 'Abholzung der Regenwälder.', 'who' => 's3', 'comments' => [['s4', 'Bäume speichern CO₂, darum ist das doppelt schlimm.']]],
                ['col' => 0, 'body' => 'Rinderhaltung erzeugt Methan.', 'who' => 's5'],
                ['col' => 1, 'title' => 'Gletscher', 'body' => 'Die Gletscher in den Alpen schmelzen sichtbar.', 'who' => 's6', 'reactions' => ['🤔']],
                ['col' => 1, 'body' => 'Mehr Hitzetage im Sommer.', 'who' => 's7'],
                ['col' => 1, 'body' => 'Der Meeresspiegel steigt.', 'who' => 's8'],
                ['col' => 2, 'body' => 'Mit dem Velo zur Schule.', 'who' => 's9', 'reactions' => ['❤️']],
                ['col' => 2, 'body' => 'Weniger Fleisch essen.', 'who' => 's10'],
                ['col' => 2, 'body' => 'Licht löschen, wenn niemand im Zimmer ist.', 'who' => 's11'],
                ['col' => 2, 'title' => 'Auftrag', 'body' => 'Wählt zu zweit eine Massnahme und schätzt ihre Wirkung ab.', 'who' => 'owner', 'author' => 'Lehrperson'],
            ],
            'bundles' => [['title' => 'Fossile Energie', 'summary' => 'Verbrennen von Kohle, Öl und Gas.', 'col' => 0, 'posts' => [0, 1]]],
        ],
        [
            'name' => 'Was macht einen guten Vortrag aus?', 'type' => 'wall', 'background' => 'morgen',
            'note' => 'Vorführung · Deutsch 1. Sek · Präsentieren · Sammlung vor der Vortragsreihe', 'tags' => 'vorführung, deutsch, präsentieren',
            'posts' => [
                ['body' => 'Blickkontakt mit der Klasse.', 'who' => 's1', 'reactions' => ['👍', '👏']],
                ['body' => 'Nicht alles ablesen.', 'who' => 's2'],
                ['body' => 'Ein Bild sagt mehr als eine Folie voller Text.', 'who' => 's3', 'reactions' => ['⭐']],
                ['body' => 'Laut und langsam sprechen.', 'who' => 's4'],
                ['body' => 'Mit einer Frage beginnen.', 'who' => 's5', 'comments' => [['s6', 'Oder mit einer Überraschung!']]],
            ],
        ],
    ],
    'targets' => [
        ['name' => 'Rückblick Projektwoche', 'aspects' => ['Zusammenarbeit', 'Planung', 'Ergebnis', 'Spass'], 'background' => 'wald', 'revealed' => true,
            'note' => 'Vorführung · Klassenrat · Projektwoche', 'tags' => 'vorführung, feedback',
            'rounds' => [['people' => 14, 'center' => [0.3, 0.5, 0.35, 0.15]]]],
    ],
    'wordclouds' => [
        ['name' => 'Was fällt dir zum Wort «Wasser» ein?', 'max_terms' => 3, 'live' => true, 'note' => 'Vorführung · NMG 6. Klasse · Wasser · Einstieg', 'tags' => 'vorführung, nmg, wasser',
            'people' => [['Meer', 'Regen', 'trinken'], ['Fluss', 'Regen'], ['See', 'schwimmen', 'Meer'], ['Eis', 'Dampf', 'Regen'], ['Leben', 'Meer'], ['Wolken', 'Regen', 'Fluss']]],
    ],
    'exams' => [
        [
            'name' => 'Zellbiologie: Gemeinschaftsprüfung', 'config' => ['quota_max' => 2, 'grading' => 'auto', 'grade' => ['on' => true], 'background' => 'graphit'], 'phase' => 'results',
            'note' => 'Vorführung · Biologie 3. Sek · Zelle · Kurztest', 'tags' => 'vorführung, biologie, zelle',
            'questions' => [
                ['type' => 'single', 'prompt' => 'Wo liegt die Erbinformation einer Pflanzenzelle?', 'options' => [['text' => 'Im Zellkern', 'correct' => true], ['text' => 'In der Vakuole', 'correct' => false], ['text' => 'In der Zellwand', 'correct' => false]]],
                ['type' => 'multi', 'prompt' => 'Was haben nur Pflanzenzellen?', 'options' => [['text' => 'Zellwand', 'correct' => true], ['text' => 'Chloroplasten', 'correct' => true], ['text' => 'Zellmembran', 'correct' => false]]],
                ['by' => 's1', 'type' => 'truefalse', 'prompt' => 'Aussagen über Mitochondrien', 'options' => [['text' => 'Sie liefern Energie.', 'correct' => true], ['text' => 'Sie kommen nur in Tieren vor.', 'correct' => false]], 'status' => 'accepted'],
            ],
            'attempts' => ['good', 'good', 'good', 'bad', 'good', 'bad'],
        ],
    ],
    'feedback' => [],
    'ai_usage' => [],
];
