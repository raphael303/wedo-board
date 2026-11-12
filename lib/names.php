<?php
declare(strict_types=1);

/**
 * Fantasienamen der Gäste: Figuren aus Büchern, Film, Fernsehen, Comics, Anime und Spielen.
 *
 * Regeln für die Liste (bei Ergänzungen einhalten):
 * - keine klaren Bösewichte und keine Figuren, mit denen man jemanden hänseln kann;
 * - keine blossen Vornamen, die in einer Klasse vorkommen können (darum «Emil Tischbein», «Königin Elsa»);
 * - eine Liste für alle Sprachen, mit der deutschen Form, wo sie abweicht (Hermine, Pippi Langstrumpf);
 * - gleich viele Frauen wie Männer (wb_name_lists() liefert beide Hälften getrennt, damit das prüfbar bleibt);
 * - höchstens 80 Zeichen, keine zwei Namen, die sich nur in Gross- und Kleinschreibung oder Akzenten unterscheiden.
 *
 * Pro Board ist ein Name eindeutig: Die Zuteilung steht in der Tabelle guest_names (Migration 12).
 */
function wb_name_lists(): array {
    static $lists = null;
    if ($lists !== null) return $lists;
    $women = [
        // Kinder- und Jugendbuch
        'Pippi Langstrumpf', 'Ronja Räubertochter', 'Madita', 'Lotta aus der Krachmacherstrasse', 'Momo', 'Heidi',
        'Klara Sesemann', 'Bibi Blocksberg', 'Die kleine Hexe', 'Biene Maja', 'Die Rote Zora', 'Pünktchen',
        'Meggie Folchart', 'Die Kindliche Kaiserin', 'Prinzessin Li Si', 'George Kirrin', 'Matilda Wurmwald',
        'Alice im Wunderland', 'Dorothy Gale', 'Wendy Darling', 'Tinker Bell', 'Mary Poppins', 'Mary Lennox',
        'Anne Shirley', 'Jo March', 'Scout Finch', 'Nancy Drew', 'Enola Holmes', 'Coraline', 'Lyra Belacqua',
        'Lucy Pevensie', 'Susan Pevensie', 'Violet Baudelaire', 'Annabeth Chase', 'Katniss Everdeen', 'Tris Prior',
        'Sofie Amundsen', 'Liesel Meminger', 'Mafalda',
        // Märchen
        'Schneewittchen', 'Aschenputtel', 'Rotkäppchen', 'Dornröschen', 'Gretel', 'Goldmarie', 'Frau Holle', 'Rapunzel',
        // Klassiker
        'Elizabeth Bennet', 'Jane Eyre', 'Miss Marple', 'Eliza Doolittle', 'Esmeralda',
        // Harry Potter und Mittelerde
        'Hermine Granger', 'Luna Lovegood', 'Minerva McGonagall', 'Ginny Weasley', 'Molly Weasley', 'Tonks',
        'Galadriel', 'Arwen', 'Éowyn', 'Tauriel',
        // Star Wars und Star Trek
        'Leia Organa', 'Rey', 'Padmé Amidala', 'Ahsoka Tano', 'Jyn Erso', 'Hera Syndulla',
        'Nyota Uhura', 'Kathryn Janeway', 'Seven of Nine', 'Beverly Crusher', 'Michael Burnham', 'Deanna Troi',
        // Comic-Heldinnen
        'Wonder Woman', 'Captain Marvel', 'Ms. Marvel', 'Shuri', 'Okoye', 'Storm', 'Supergirl', 'Batgirl', 'Gamora',
        'Spider-Gwen', 'Peggy Carter', 'Pepper Potts', 'Lois Lane',
        // Trickfilm
        'Vaiana', 'Mulan', 'Merida', 'Königin Elsa', 'Anna von Arendelle', 'Meerjungfrau Arielle', 'Belle',
        'Prinzessin Tiana', 'Prinzessin Jasmin', 'Mirabel Madrigal', 'Löwin Nala', 'Jane Porter', 'Dory', 'Judy Hopps',
        'Elastigirl', 'Violetta Parr', 'Cowgirl Jessie', 'Bo Peep', 'Vanellope von Schweetz', 'Prinzessin Fiona',
        'Astrid Hofferson', 'Meisterin Tigress', 'Daisy Duck', 'Minnie Maus', 'Oma Duck',
        'Lisa Simpson', 'Marge Simpson', 'Velma Dinkley', 'Kim Possible',
        // Anime
        'Chihiro', 'Nausicaä', 'Prinzessin Mononoke', 'Hexe Kiki', 'Sophie Hatter', 'Sailor Moon', 'Korra', 'Katara',
        'Toph Beifong', 'Bulma', 'Nami', 'Nico Robin', 'Sakura Haruno', 'Hinata Hyuga', 'Mikasa Ackermann', 'Misty',
        // Film und Fernsehen
        'Dana Scully', 'Ellen Ripley', 'Sarah Connor', 'Trinity', 'Furiosa', 'Leeloo', 'Neytiri', 'Ellie Sattler',
        'Marion Ravenwood', 'Miss Moneypenny', 'Amélie Poulain', 'Eleven', 'Max Mayfield', 'Wednesday Addams',
        'Buffy Summers', 'Xena', 'Arya Stark', 'River Song', 'Donna Noble', 'Rory Gilmore', 'Phoebe Buffay', 'Leslie Knope',
        // Spiele
        'Lara Croft', 'Prinzessin Zelda', 'Prinzessin Peach', 'Samus Aran', 'Aloy', 'Chun-Li',
    ];
    $men = [
        // Kinder- und Jugendbuch
        'Jim Knopf', 'Lukas der Lokomotivführer', 'Michel aus Lönneberga', 'Karlsson vom Dach', 'Emil Tischbein',
        'Pumuckl', 'Der kleine Prinz', 'Bastian Balthasar Bux', 'Atréju', 'Fuchur', 'Das Sams',
        'Der kleine Nick', 'Pettersson', 'Kater Findus', 'Der Grüffelo', 'Nils Holgersson', 'Wickie',
        'Beppo Strassenkehrer', 'Globi', 'Schellen-Ursli', 'Papa Moll', 'Pingu', 'Kasperli',
        'Geissenpeter', 'Peter Pan', 'Pinocchio', 'Paddington', 'Pu der Bär', 'Tigger', 'Dr. Dolittle',
        'Willy Wonka', 'Aslan', 'Percy Jackson', 'Eragon', 'Artemis Fowl',
        // Klassiker und Sagen
        'Robin Hood', 'Zorro', 'Tarzan', 'D’Artagnan', 'Don Quijote', 'Sancho Pansa', 'Odysseus', 'Robinson Crusoe',
        'Kapitän Nemo', 'Phileas Fogg', 'Tom Sawyer', 'Huckleberry Finn', 'Oliver Twist', 'Gulliver',
        'Baron Münchhausen', 'Till Eulenspiegel', 'Wilhelm Tell', 'König Artus', 'Zauberer Merlin', 'Aladdin', 'Sherlock Holmes', 'Dr. Watson', 'Hercule Poirot',
        // Harry Potter und Mittelerde
        'Harry Potter', 'Ron Weasley', 'Albus Dumbledore', 'Rubeus Hagrid', 'Neville Longbottom',
        'Sirius Black', 'Gandalf', 'Frodo Beutlin', 'Bilbo Beutlin', 'Samweis Gamdschie', 'Aragorn', 'Legolas',
        'Gimli', 'Pippin', 'Merry', 'Baumbart',
        // Star Wars und Star Trek
        'Luke Skywalker', 'Han Solo', 'Yoda', 'Obi-Wan Kenobi', 'Chewbacca', 'R2-D2', 'C-3PO', 'Grogu', 'Mr. Spock', 'James T. Kirk', 'Jean-Luc Picard', 'Data',
        'Dr. McCoy',
        // Comic-Helden
        'Captain America', 'Spider-Man', 'Iron Man', 'Thor', 'Black Panther', 'Groot', 'Rocket Raccoon',
        'Doctor Strange', 'Superman', 'Batman', 'Asterix', 'Idefix', 'Miraculix',
        'Kapitän Haddock', 'Professor Bienlein', 'Lucky Luke', 'Snoopy', 'Charlie Brown', 'Garfield', 'Hobbes',
        'Marsupilami',
        // Trickfilm
        'Balu', 'Mogli', 'Baghira', 'Simba', 'Nemo', 'Wall-E', 'Buzz Lightyear', 'Sheriff Woody',
        'Schneemann Olaf', 'Stitch', 'Baymax', 'Micky Maus', 'Donald Duck', 'Dagobert Duck', 'Daniel Düsentrieb',
        'Bugs Bunny', 'Scooby-Doo', 'Der rosarote Panther', 'Bart Simpson', 'SpongeBob Schwammkopf',
        'Kermit der Frosch', 'Ernie', 'Krümelmonster',
        // Anime
        'Totoro', 'Son Goku', 'Naruto', 'Monkey D. Ruffy', 'Pikachu', 'Ash Ketchum', 'Detektiv Conan', 'Doraemon',
        'Aang',
        // Film und Fernsehen
        'Indiana Jones', 'James Bond', 'MacGyver', 'Inspektor Columbo', 'Marty McFly', 'Doc Brown', 'E.T.', 'Neo', 'Forrest Gump', 'Captain Jack Sparrow', 'Mr. Miyagi', 'Doctor Who', 'Alf', 'Pan Tau',
        // Spiele
        'Super Mario', 'Link', 'Yoshi', 'Sonic', 'Kirby', 'Pac-Man',
    ];
    return $lists = ['women' => $women, 'men' => $men];
}

/** Alle Namen als eine Liste; Frauen und Männer abwechselnd, damit die Reihenfolge nichts bevorzugt. */
function wb_name_pool(): array {
    static $pool = null;
    if ($pool !== null) return $pool;
    ['women' => $w, 'men' => $m] = wb_name_lists();
    $pool = [];
    for ($i = 0, $n = max(count($w), count($m)); $i < $n; $i++) {
        if (isset($w[$i])) $pool[] = $w[$i];
        if (isset($m[$i])) $pool[] = $m[$i];
    }
    return $pool;
}

/**
 * Name ohne Zuteilung, nur aus Browser-Schlüssel und Board-Schlüssel. Wird gebraucht, solange die Tabelle
 * guest_names noch fehlt (zwischen Ausrollen und Migration), und als Startpunkt der Zuteilung.
 */
function wb_fantasy_name(string $uid, string $boardKey, string $lang = ''): string {
    $pool = wb_name_pool();
    return $pool[crc32($uid . '|' . $boardKey) % count($pool)];
}

/**
 * Der Fantasiename eines Gastes auf einem Board. Beim ersten Aufruf wird ein freier Name zugeteilt und
 * in guest_names festgehalten; danach bleibt er gleich. Auf einem Board trägt niemand denselben Namen.
 *
 * Sind alle Namen vergeben, wird zuerst die älteste Zuteilung eines Gastes frei, der auf dem Board nie
 * geschrieben hat (blosse Besuche, verlorene Cookies) und dessen Zuteilung älter als zehn Minuten ist. Erst wenn es keine solche gibt, folgen Namen mit
 * Nummer («Gandalf 2»).
 */
function wb_guest_name(array $board, string $uid): string {
    $boardId = (int)$board['id'];
    $fallback = wb_fantasy_name($uid, (string)$board['bkey']);
    try {
        for ($try = 0; $try < 6; $try++) {
            $row = db_row('SELECT name FROM guest_names WHERE board_id = ? AND guest_key = ?', [$boardId, $uid]);
            if ($row) return (string)$row['name'];
            $name = wb_guest_name_pick($board, $uid);
            try {
                db_exec('INSERT INTO guest_names (board_id, guest_key, name, created_at) VALUES (?, ?, ?, ?)', [$boardId, $uid, $name, wb_now()]);
                return $name;
            } catch (PDOException $e) {
                // Gleichzeitig hat jemand denselben Namen erhalten oder derselbe Browser eine zweite Anfrage gestellt: neu versuchen.
                if ($e->getCode() !== '23000') throw $e;
            }
        }
    } catch (PDOException $e) {
        // Tabelle fehlt noch (vor Migration 12): Name ohne Zuteilung.
        if ($e->getCode() !== '42S02') throw $e;
    }
    return $fallback;
}

/** Wählt einen freien Namen für das Board; gibt bei Bedarf eine ungenutzte Zuteilung frei. */
function wb_guest_name_pick(array $board, string $uid): string {
    $boardId = (int)$board['id'];
    $pool = wb_name_pool();
    $h = crc32($uid . '|' . $board['bkey']);
    $taken = array_flip(array_map(fn($r) => mb_strtolower((string)$r['name']), db_all('SELECT name FROM guest_names WHERE board_id = ?', [$boardId])));
    $free = array_values(array_filter($pool, fn($n) => !isset($taken[mb_strtolower($n)])));
    if ($free) return $free[$h % count($free)];

    // Nur Zuteilungen, die älter als zehn Minuten sind: Eine frische gehört vielleicht zu einem Beitrag, der gerade gespeichert wird.
    $idle = db_row('SELECT g.guest_key, g.name FROM guest_names g
        WHERE g.board_id = ? AND g.created_at < ?
          AND NOT EXISTS (SELECT 1 FROM posts p WHERE p.board_id = g.board_id AND p.author_key = g.guest_key)
          AND NOT EXISTS (SELECT 1 FROM comments c WHERE c.board_id = g.board_id AND c.author_key = g.guest_key)
        ORDER BY g.created_at, g.guest_key LIMIT 1', [$boardId, (new DateTime('-10 minutes', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')]);
    if ($idle) {
        db_exec('DELETE FROM guest_names WHERE board_id = ? AND guest_key = ?', [$boardId, $idle['guest_key']]);
        return (string)$idle['name'];
    }
    for ($round = 2; $round < 50; $round++) {
        $free = array_values(array_filter($pool, fn($n) => !isset($taken[mb_strtolower($n . ' ' . $round)])));
        if ($free) return $free[$h % count($free)] . ' ' . $round;
    }
    return $pool[$h % count($pool)] . ' ' . random_int(50, 9999);
}
