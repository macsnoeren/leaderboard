<?php
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/auth.php';
require_once '../conf/game.php';

$db = getDB();
// Uitleg voor alle operators; hangt niet aan het actieve spel
$user = requireTeacher($db);

// Beslisregels per oordeel: [label, zeker?, suggest, reply, auto]
$decisionRows = [
    ['onvoldoende', 'ja', 'Suggestie', 'Feedback aan het team', 'Feedback aan het team'],
    ['onvoldoende', 'nee', 'Suggestie', 'Operator nodig', 'Operator nodig'],
    ['voldoende of goed', 'ja', 'Suggestie met level-up-advies', 'Feedback aan het team, level-up-advies voor de operator', 'Level omhoog + bericht aan het team'],
    ['voldoende of goed', 'nee', 'Suggestie met level-up-advies', 'Operator nodig', 'Operator nodig'],
    ['vraag', 'ja', 'Voorgestelde reactie', 'Reactie aan het team', 'Reactie aan het team'],
    ['vraag', 'nee', 'Voorgestelde reactie', 'Operator nodig', 'Operator nodig'],
];

$pageTitle = 'Zo werkt de AI';
$extraCSS = '<style>
    .explain { max-width: 960px; }
    .explain p, .explain li { line-height: 1.6; }
    .explain p + p { margin-top: 10px; }
    .explain ul.plain { padding-left: 20px; margin-top: 8px; }
    .explain ul.plain li + li { margin-top: 4px; }
    .toc { display: flex; flex-wrap: wrap; gap: 8px; }
    .toc a { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: var(--radius-pill); background: var(--surface-2); border: 1px solid var(--line); color: var(--text); font-size: 13px; text-decoration: none; }
    .toc a:hover { border-color: var(--brand); color: var(--brand); }
    .principles { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; margin-top: 14px; }
    .principle { border: 1px solid var(--line); border-radius: var(--radius-sm); padding: 12px 14px; background: var(--surface-2); }
    .principle strong { display: flex; align-items: center; gap: 6px; margin-bottom: 4px; }
    .principle .icon { width: 16px; height: 16px; color: var(--brand); }
    .principle p { font-size: 14px; color: var(--text-muted); }
    .flow { list-style: none; counter-reset: step; margin-top: 16px; }
    .flow > li { position: relative; padding: 0 0 20px 48px; }
    .flow > li::before { counter-increment: step; content: counter(step); position: absolute; left: 0; top: 0; width: 32px; height: 32px; border-radius: 50%; background: var(--brand-soft); color: var(--brand); font-family: var(--font-mono); font-weight: 700; display: grid; place-items: center; }
    .flow > li::after { content: ""; position: absolute; left: 15px; top: 38px; bottom: 6px; width: 2px; background: var(--line); }
    .flow > li:last-child { padding-bottom: 0; }
    .flow > li:last-child::after { display: none; }
    .flow .step-title { font-weight: 700; display: flex; flex-wrap: wrap; align-items: center; gap: 8px; min-height: 32px; }
    .flow .step-title .badge { font-weight: 600; }
    .flow p { font-size: 14px; }
    .branch { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; margin-top: 8px; }
    .branch > div { border: 1px solid var(--line); border-radius: var(--radius-sm); padding: 12px 14px; }
    .branch h3 { font-size: 14px; display: flex; align-items: center; gap: 6px; margin-bottom: 4px; }
    .branch .icon { width: 15px; height: 15px; color: var(--text-muted); }
    .examples { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; margin-top: 12px; }
    .example { display: flex; flex-direction: column; gap: 8px; border: 1px solid var(--line); border-radius: var(--radius-sm); padding: 12px; }
    .example .bubble { max-width: 100%; font-size: 14px; }
    .example-label { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
    .example-label .icon { width: 14px; height: 14px; }
    .example-label.is-bad { color: var(--danger); }
    .example-label.is-good { color: var(--success); }
    .rubric-sample td, .rubric-sample th { white-space: normal; }
    .explain .table td { vertical-align: top; }
    .explain .card { scroll-margin-top: 16px; }
    .explain code { font-family: var(--font-mono); font-size: 13px; background: var(--surface-2); padding: 1px 5px; border-radius: 4px; }
</style>';
include 'admin_header.php';
?>
<div class="explain">
    <?= pageHeader('Zo werkt de AI', '<span>Hoe de agentic AI helpt bij rubrics en bij het beantwoorden van teams, en hoe haar antwoorden tot stand komen</span>') ?>

    <div class="card">
        <div class="card-title"><?= icon('bot') ?>In het kort</div>
        <p>OperationRoom gebruikt <strong>agentic AI</strong>: geen enkele chatbot die alles doet, maar een reeks kleine AI-agents met elk één taak, die elkaars werk controleren. Vaste regels in de code beslissen wat er met hun uitkomst gebeurt. De AI doet twee dingen:</p>
        <ul class="plain">
            <li><strong>Rubric maken</strong>: de opdracht-assistent leest je opdracht en je antwoord mee, stelt vragen en stelt een rubric voor. Jij keurt hem goed.</li>
            <li><strong>Teams beantwoorden</strong>: elk teambericht gaat langs de agents. Afhankelijk van de AI-modus van het team krijg jij een suggestie, krijgt het team een reactie, of zet de AI het team zelf een level omhoog.</li>
        </ul>
        <div class="principles">
            <div class="principle"><strong><?= icon('user') ?>De operator beslist</strong><p>Jij kiest per team wat de AI mag. Zonder goedgekeurde rubric doet de AI alleen suggesties.</p></div>
            <div class="principle"><strong><?= icon('hand') ?>Bij twijfel naar jou</strong><p>Twijfelt een agent, zijn ze het oneens of gaat er iets mis, dan krijgt het team niets en zie jij “Operator nodig”.</p></div>
            <div class="principle"><strong><?= icon('shield-check') ?>De server controleert</strong><p>De website voert een AI-besluit alleen uit als het spel en de instellingen het toestaan. Een besluit kan daar alleen lager uitvallen, nooit hoger.</p></div>
            <div class="principle"><strong><?= icon('eye') ?>Alles is na te lezen</strong><p>Elke beoordeling, met de uitvoer en redenering van de agents, staat in het <a href="ai_log.php">AI-logboek</a>.</p></div>
        </div>
    </div>

    <nav class="card" aria-label="Op deze pagina">
        <div class="section-label">Op deze pagina</div>
        <div class="toc">
            <a href="#modi"><?= icon('sliders') ?>AI-modi</a>
            <a href="#rubric"><?= icon('clipboard') ?>Rubric maken</a>
            <a href="#stappen"><?= icon('message') ?>Een teambericht, stap voor stap</a>
            <a href="#formulering"><?= icon('pencil') ?>Hoe antwoorden worden geformuleerd</a>
            <a href="#beslissen"><?= icon('check-circle') ?>Wat er met de uitkomst gebeurt</a>
            <a href="#escalatie"><?= icon('hand') ?>Operator nodig</a>
            <a href="#controle"><?= icon('trending-up') ?>Controle achteraf</a>
            <a href="#replay"><?= icon('refresh') ?>AI-replay</a>
            <a href="#privacy"><?= icon('lock') ?>Privacy en modellen</a>
        </div>
    </nav>

    <section class="card" id="modi">
        <div class="card-title"><?= icon('sliders') ?>AI-modi: wat mag de AI per team?</div>
        <p>De modus stel je per team in op het <a href="teacher.php">dashboard</a>, of voor alle teams tegelijk. In <a href="game.php">Spelinstellingen</a> kies je de standaardmodus voor nieuwe teams en de limieten: hoeveel antwoorden en vragen een team per level per 10 minuten mag sturen voordat de AI het aan jou overlaat.</p>
        <div class="table-scroll" style="margin-top: 14px;">
            <table class="table">
                <thead><tr><th>Modus</th><th>Wat de AI doet</th><th>Antwoordt team</th><th>Zet level omhoog</th></tr></thead>
                <tbody>
                    <?php foreach (AI_MODES as $mode): ?>
                        <tr>
                            <td class="nowrap"><?= aiModeBadge($mode) ?></td>
                            <td><?= htmlspecialchars(AI_MODE_DESCRIPTIONS[$mode]) ?></td>
                            <td><?= in_array($mode, ['reply', 'auto'], true) ? icon('check') . ' ja' : '<span class="muted">nee</span>' ?></td>
                            <td><?= $mode === 'auto' ? icon('check') . ' ja' : '<span class="muted">nee</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <ul class="plain">
            <li><strong>Antwoorden</strong> en <strong>Automatisch</strong> werken alleen bij een opdracht met een goedgekeurde rubric (<?= rubricStatusBadge('approved') ?>). Zonder rubric valt de AI voor die opdracht terug op suggesties.</li>
            <li>Vink bij een opdracht <em>Altijd door een mens</em> aan, dan zet de AI daar nooit zelf een level omhoog: <strong>Automatisch</strong> werkt dan als <strong>Antwoorden</strong>.</li>
            <li>Staat het spel op pauze, dan doet de AI alleen suggesties.</li>
            <li>Teams zien niet of een bericht van jou of van de AI komt: beide verschijnen als “Operator”. In Berichten zie jij wel wat van de AI kwam.</li>
        </ul>
    </section>

    <section class="card" id="rubric">
        <div class="card-title"><?= icon('clipboard') ?>Rubric maken met de opdracht-assistent</div>
        <p>De AI beoordeelt antwoorden van teams met een <strong>rubric</strong>: per aspect van het antwoord staat beschreven wat onvoldoende, voldoende en goed is. Je kunt de rubric zelf invullen bij een opdracht in <a href="assignments.php">Opdrachten</a>, of de opdracht-assistent laten helpen met <em>Laat de AI meekijken</em>.</p>
        <ol class="flow">
            <li>
                <div class="step-title">Jij schrijft de opdracht en het antwoord</div>
                <p>De assistent leest de titel, beschrijving, instructie en de beoordelingscriteria (het antwoord, dat teams nooit zien). Bijlagen leest de AI niet.</p>
            </li>
            <li>
                <div class="step-title">De assistent stelt vragen <span class="badge">max. 3 rondes</span></div>
                <p>Is het antwoord niet eenduidig, dan stelt hij 1 tot 5 korte vragen. Bijvoorbeeld: wat is minimaal nodig voor voldoende? Tellen synoniemen ook? En als het antwoord naar de bijlage verwijst (“zie dossier”): wat staat daar precies? Na drie rondes stelt hij altijd een rubric voor.</p>
            </li>
            <li>
                <div class="step-title">De assistent stelt een rubric voor <?= rubricStatusBadge('draft') ?></div>
                <p>Het voorstel verschijnt als tabel in het formulier. Je kunt alles aanpassen.</p>
            </li>
            <li>
                <div class="step-title">Jij keurt goed <?= rubricStatusBadge('approved') ?></div>
                <p>Pas dan mag de AI teams zelf antwoorden. Pas je later de instructie of de criteria aan, dan gaat de rubric terug naar concept en moet je hem opnieuw goedkeuren.</p>
            </li>
        </ol>
        <div class="section-label">Wat er in een rubric staat</div>
        <div class="table-scroll">
            <table class="table table-compact rubric-sample">
                <thead><tr><th>Onderdeel</th><th>Betekenis</th><th>Voorbeeld</th></tr></thead>
                <tbody>
                    <tr><td><strong>Kern</strong></td><td>Wat het team minimaal moet geven voor voldoende.</td><td>Het team moet de dader én het motief noemen.</td></tr>
                    <tr><td><strong>Aspecten</strong></td><td>1 tot 5 onderdelen, elk verplicht of niet, met een omschrijving van onvoldoende, voldoende en goed.</td><td>Dader: <span class="badge badge-onvoldoende">onvoldoende</span> geen of verkeerde persoon, <span class="badge badge-voldoende">voldoende</span> de conciërge zonder naam, <span class="badge badge-goed">goed</span> Jan de Vries (de conciërge).</td></tr>
                    <tr><td><strong>Ook goed</strong></td><td>Varianten en synoniemen die meetellen.</td><td>meneer De Vries</td></tr>
                    <tr><td><strong>Veelgemaakte fouten</strong></td><td>Te verwachten foute antwoorden.</td><td>De tuinman: alibi bevestigd op camera 3.</td></tr>
                    <tr><td><strong>Verduidelijkingen</strong></td><td>Uitleg over de vraag die de AI aan teams mag geven, zonder iets van het antwoord.</td><td>Met “motief” bedoelen we: waarom deed de dader het?</td></tr>
                    <tr><td><strong>Niveauregel</strong></td><td>Wanneer het geheel voldoende of goed is.</td><td><?= htmlspecialchars(RUBRIC_DEFAULT_RULE) ?></td></tr>
                </tbody>
            </table>
        </div>
        <div class="section-label">Afspraken voor de assistent</div>
        <ul class="plain">
            <li>Hij <strong>verzint geen feiten</strong>: elke naam, tijd, plaats of elk getal in de rubric moet in jouw tekst of in jouw antwoorden staan. Anders vraagt hij het eerst.</li>
            <li>Hij <strong>verandert jouw antwoord nooit</strong>: de criteria blijven zoals jij ze schreef.</li>
            <li>Jouw antwoord is een <strong>uitstekend</strong> antwoord: dat is het niveau <span class="badge badge-goed">goed</span>. Voor <span class="badge badge-voldoende">voldoende</span> is duidelijk minder nodig: de kern klopt, details mogen ontbreken of minder precies zijn. Bestaat jouw antwoord uit een opsomming (bijvoorbeeld vier punten), dan is een deel daarvan voldoende.</li>
            <li>Alleen de kern is verplicht (hooguit twee aspecten); details en extra punten maken het verschil tussen voldoende en goed. Wil je het strenger, zet dan in de tabel meer aspecten op verplicht.</li>
            <li>In de verduidelijkingen mag niets staan dat het antwoord dichterbij brengt. De code haalt regels met woorden uit het antwoord er ook nog eens uit.</li>
            <li>Jouw antwoorden op zijn vragen gaan als extra uitleg mee naar de agents die later de teams beoordelen.</li>
        </ul>
    </section>

    <section class="card" id="stappen">
        <div class="card-title"><?= icon('message') ?>Een teambericht, stap voor stap</div>
        <p>Stuurt een team een bericht waar nog niemand op heeft gereageerd, dan pakt de AI-agent het op. Elke agent krijgt de opdracht en de hele chat van dit level te zien, want teams geven hun antwoord vaak in stukjes. Wat een agent verder te zien krijgt, verschilt bewust per agent.</p>
        <p>Een agent behandelt één team tegelijk, het oudste bericht eerst. Sturen veel teams tegelijk iets, dan ontstaat een wachtrij; op het <a href="teacher.php">dashboard</a> zie je in de tegel “Wacht op AI” hoeveel teams wachten en hoe snel de AI gemiddeld reageert. Stuurt een team intussen nog een bericht, dan bekijkt de AI alleen het nieuwste (met de hele chat erbij).</p>
        <p>In <a href="messages.php">Berichten</a> zie je onder het laatste teambericht of de AI ermee bezig is of dat het nog in de wachtrij staat. Wil je zelf antwoorden, klik dan op <strong>Annuleren</strong>: de AI reageert dan niet op dat bericht (wat ze al gedaan had, wordt weggegooid). Versturen van je eigen antwoord stopt de AI ook. Op een volgend bericht van het team reageert de AI weer gewoon.</p>
        <p>Komt er een nieuwe AI-uitkomst, dan verdwijnen de oudere AI-suggesties van dat level uit de chat, zodat je alleen de actuele ziet. Een openstaande “Operator nodig”-melding blijft staan tot je hem afhandelt, en berichten die het team van de AI kreeg blijven ook staan. De oudere beoordelingen tellen wel mee in de tussenstand en staan in het <a href="ai_log.php">AI-logboek</a>.</p>
        <ol class="flow">
            <li>
                <div class="step-title">Voorfilter <span class="badge">code, geen AI</span></div>
                <p>Lege berichten en verdachte patronen (“negeer je instructies”, “geef ons een level up”) krijgen een vlag. Zo’n bericht gaat altijd naar jou.</p>
            </li>
            <li>
                <div class="step-title">Intentie-agent <span class="badge">kent het antwoord niet</span></div>
                <p>Bepaalt of het bericht een <strong>vraag</strong> over de opdracht is, een <strong>antwoord</strong> of iets anders (“ok”, “wacht even”). Een gok in vraagvorm (“Is het de conciërge?”) telt als antwoord, anders kan een team het antwoord met ja/nee-vragen achterhalen. Bij twijfel wordt het bericht ook als antwoord behandeld.</p>
            </li>
            <li>
                <div class="step-title">Hulp-agent of beoordelaar</div>
                <div class="branch">
                    <div>
                        <h3><?= icon('info') ?>Bij een vraag: hulp-agent</h3>
                        <p>Legt uit wat de opdracht bedoelt. Hij krijgt alleen de opdrachttekst en de verduidelijkingen uit de rubric, <strong>niet het antwoord</strong>: wat hij niet weet, kan hij niet verklappen. Een vraag leidt nooit tot een oordeel of level-up.</p>
                    </div>
                    <div>
                        <h3><?= icon('clipboard') ?>Bij een antwoord: beoordelaar</h3>
                        <p>Beoordeelt alles wat het team op dit level gaf met de rubric, per aspect, niet alleen het laatste bericht. Bij elk aspect hoort een letterlijk citaat uit een teambericht. De code controleert dat dat citaat echt in dat bericht staat. Zet de beoordelaar een aspect lager dan het team eerder al bereikte, zonder dat het team er iets nieuws over zei, dan krijgt hij één keer de kans om het opnieuw te doen, met die aspecten erbij.</p>
                    </div>
                </div>
            </li>
            <li>
                <div class="step-title">Validator <span class="badge">groter model</span><span class="badge">kent het antwoord</span></div>
                <p>Een tweede, sterkere AI controleert het werk onafhankelijk en is bewust streng. Klopt het oordeel? Verklapt de reactie iets, of bevestigt of ontkent ze een gok? Probeert het team de AI te manipuleren? Was het wel echt een vraag? Twijfelt de validator, dan zegt hij “operator nodig”.</p>
            </li>
            <li>
                <div class="step-title">Beslisregels <span class="badge">code, geen AI</span></div>
                <p>Vaste regels bepalen wat er gebeurt, op basis van het oordeel, of de agents het eens zijn en de modus van het team. Zie <a href="#beslissen">Wat er met de uitkomst gebeurt</a>.</p>
            </li>
            <li>
                <div class="step-title">Controle door de website</div>
                <p>De website voert het besluit alleen uit als het spel loopt, het bericht en het level nog actueel zijn en de modus, de rubric en een eventuele open melding het toestaan. Anders wordt het besluit lager: bijvoorbeeld een suggestie in plaats van een reactie. Elke AI-actie komt in de auditlog.</p>
            </li>
        </ol>
        <div class="section-label">Wat telt bij het beoordelen</div>
        <ul class="plain">
            <li>Alle berichten van het level tellen samen: geeft een team eerst deel A en later deel B, dan is het antwoord compleet. Het laatste standpunt per aspect telt (eerst Jan, later Piet: dan geldt Piet).</li>
            <li>Meerdere antwoorden tegelijk noemen (“Jan, Piet of Klaas”) is onvoldoende voor dat aspect: hagelschieten levert niets op.</li>
            <li>Bevestigde jij een aspect in de chat (“de dader klopt”), dan telt dat aspect als bereikt.</li>
            <li>Alleen wat echt in de chat staat telt; “dat hadden we toch al gezegd” zonder dat bericht telt niet.</li>
            <li>Per team per level houdt de AI een <strong>tussenstand</strong> bij: per aspect het laatste standpunt van het team, met citaat. Gaat een nieuw bericht over een ander aspect, dan blijft een groen aspect groen. Zegt het team later iets fouts over een groen aspect, dan gaat dat aspect weer naar rood: het team gaat de verkeerde kant op. Staan alle verplichte aspecten op voldoende of goed, dan moet het oordeel ook voldoende of goed zijn; zegt de beoordelaar toch onvoldoende, dan gaat het naar jou. De tussenstand zie je in het AI-paneel in <a href="messages.php">Berichten</a> en kun je daar wissen. Teams zien hem nooit.</li>
        </ul>
    </section>

    <section class="card" id="formulering">
        <div class="card-title"><?= icon('pencil') ?>Hoe antwoorden worden geformuleerd</div>
        <p>De beoordelaar schrijft twee teksten: een korte toelichting voor jou (wat goed is en wat ontbreekt) en feedback voor het team. De hulp-agent schrijft bij een vraag alleen een reactie voor het team. Voor teams gelden deze regels:</p>
        <ul class="plain">
            <li>In het Nederlands, in de jullie-vorm, vriendelijk en kort: maximaal drie zinnen, zonder ondertekening. De AI zegt nooit dat ze een AI is.</li>
            <li><span class="badge badge-goed">goed</span> De feedback begint met “Uitstekend!”, zonder vervolgvraag: het team gaat door naar de volgende opdracht.</li>
            <li><span class="badge badge-voldoende">voldoende</span> De feedback begint met “Goed genoeg, maar …”. Het team gaat ook door; het verschil met goed bewaren we voor jou.</li>
            <li><span class="badge badge-onvoldoende">onvoldoende</span> Een hint die de goede kant op wijst, <strong>zonder</strong> het antwoord, namen, plaatsen of tijden.</li>
            <li>Een gok wordt <strong>nooit bevestigd of ontkend</strong>, ook niet indirect. Dus ook geen “jullie zitten warm” en geen herhaling van een persoon of tijd die het team noemde zolang dat aspect nog niet goed is.</li>
            <li>Een reactie op een vraag legt alleen uit wat de opdracht vraagt. Kan dat niet zonder iets te verklappen, dan verwijst de AI naar de opdrachttekst of laat ze het aan jou over.</li>
            <li>Teams kennen de rubric en jouw criteria niet. De feedback noemt dus nooit de rubric, aspecten, criteria of hoeveel punten er nodig zijn (“alle vier de punten moeten erin staan”), maar verwijst naar wat de opdracht zelf vraagt. De code controleert dat ook.</li>
            <li>Merkt de validator dat de feedback iets verklapt, dan gaat die feedback nooit naar het team.</li>
            <li>Zet de AI een team een level omhoog, dan komt de feedback op het nieuwe level, zodat het team hem ziet.</li>
        </ul>
        <div class="section-label">Voorbeeld: het team gokt</div>
        <div class="examples">
            <div class="example">
                <div class="bubble bubble-team"><div class="bubble-meta"><strong>Team</strong></div>Is het de conciërge?</div>
                <div class="example-label is-bad"><?= icon('x') ?>Zo niet</div>
                <div class="bubble bubble-ai"><div class="bubble-meta"><?= icon('bot') ?><strong>AI</strong></div>Probeer de naam van de conciërge te noemen.</div>
                <p class="small muted">Verraadt dat de conciërge de dader is.</p>
            </div>
            <div class="example">
                <div class="bubble bubble-team"><div class="bubble-meta"><strong>Team</strong></div>Is het de conciërge?</div>
                <div class="example-label is-good"><?= icon('check') ?>Zo wel</div>
                <div class="bubble bubble-ai"><div class="bubble-meta"><?= icon('bot') ?><strong>AI</strong></div>Jullie zijn er nog niet: wie precies, en waarom?</div>
                <p class="small muted">Wijst de weg zonder de gok te bevestigen of te ontkennen.</p>
            </div>
        </div>
    </section>

    <section class="card" id="beslissen">
        <div class="card-title"><?= icon('check-circle') ?>Wat er met de uitkomst gebeurt</div>
        <p>Een uitkomst is <strong>zeker</strong> als beoordelaar en validator hetzelfde oordeel geven, de validator akkoord is, niemand om een operator vraagt, alle citaten kloppen, de rubric is goedgekeurd en het team onder de limiet zit. Bij een vraag: de hulp-agent kan hem beantwoorden en de validator ziet niets verklappends of misleidends.</p>
        <div class="table-scroll" style="margin-top: 14px;">
            <table class="table table-compact">
                <thead><tr><th>Uitkomst</th><th>Zeker?</th><th><?= htmlspecialchars(AI_MODE_LABELS['suggest']) ?></th><th><?= htmlspecialchars(AI_MODE_LABELS['reply']) ?></th><th><?= htmlspecialchars(AI_MODE_LABELS['auto']) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($decisionRows as [$outcome, $sure, $suggest, $reply, $auto]): ?>
                        <tr>
                            <td><?= htmlspecialchars($outcome) ?></td>
                            <td><?= $sure === 'ja' ? icon('check') . ' ja' : icon('alert') . ' nee' ?></td>
                            <td><?= htmlspecialchars($suggest) ?></td>
                            <td><?= $reply === 'Operator nodig' ? '<span class="badge badge-warning">' . icon('hand') . 'Operator nodig</span>' : htmlspecialchars($reply) ?></td>
                            <td><?= $auto === 'Operator nodig' ? '<span class="badge badge-warning">' . icon('hand') . 'Operator nodig</span>' : htmlspecialchars($auto) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <ul class="plain">
            <li>Een manipulatiepoging, een vlag van het voorfilter of een fout van het AI-model leidt in <strong>elke</strong> modus tot “Operator nodig”, ook bij Suggesties.</li>
            <li>Een suggestie zie je in Berichten met het oordeel, de reden en de voorgestelde reactie. Je kunt hem <em>Versturen</em>, <em>Overnemen</em> om eerst aan te passen, of <em>Verwerpen</em>.</li>
        </ul>
    </section>

    <section class="card" id="escalatie">
        <div class="card-title"><?= icon('hand') ?>Operator nodig</div>
        <p>Twijfelt de AI, dan zie je “Operator nodig” op het dashboard, in de zijbalk en bij het team in <a href="messages.php">Berichten</a>, met het voorlopige oordeel en de reden. Het team krijgt niets, en zolang de melding openstaat stuurt de AI dat team niets en zet ze geen level.</p>
        <p>De melding is afgehandeld zodra jij het team antwoordt, het level verandert, of je een van de knoppen bij de melding gebruikt: <em>Eens → level up</em>, <em>Eens → reactie sturen</em>, <em>Oneens → zelf antwoorden</em> of <em>Afgehandeld</em>.</p>
    </section>

    <section class="card" id="controle">
        <div class="card-title"><?= icon('trending-up') ?>Controle achteraf</div>
        <p>In het <a href="ai_log.php">AI-logboek</a> staat elke beoordeling van het actieve spel: wat elke agent zei, met zijn redenering, en wat er uiteindelijk gebeurde. Markeer beoordelingen met <em>AI had gelijk</em> of <em>AI had ongelijk</em>; de knoppen bij “Operator nodig” tellen ook mee. Zo zie je per opdracht hoe vaak de AI het met de operators eens was.</p>
        <?= alertBox('info', '<strong>Advies:</strong> begin een nieuw spel met <strong>Suggesties</strong> of <strong>Antwoorden</strong>. Zet <strong>Automatisch</strong> pas aan als de overeenstemming per opdracht minstens 90 % is en er geen onterechte level-ups waren.') ?>
    </section>

    <section class="card" id="replay">
        <div class="card-title"><?= icon('refresh') ?>AI-replay: een gespeeld spel opnieuw laten beoordelen</div>
        <p>Is de AI aangepast, dan wil je weten of ze het nu beter doet. Kies bij <a href="game.php">Spelinstellingen</a> onder <em>AI-replay</em> een of meer teams (hooguit tien). De AI-agent loopt hun chats dan level voor level door en beoordeelt elk teambericht opnieuw, met de chat tot dat moment, alsof het team in de modus <strong>Automatisch</strong> stond. Er gaat niets naar de teams en er verandert niets in het spel. Het rapport (HTML) verschijnt bij <a href="jobs.php">Taken</a>.</p>
        <ul class="plain">
            <li>Per beurt zie je links wat er <strong>toen</strong> gebeurde (de AI van toen en wat de operator deed) en rechts wat de AI <strong>nu</strong> zou doen, met de reactie aan het team en de tussenstand.</li>
            <li>Wat er toen goed was, haalt de replay uit jullie eigen handelen: het bericht waarna een operator het team doorzette was goed; een bericht waarop een operator antwoordde zonder door te zetten nog niet; een level-up door de AI die een operator terugdraaide was fout; anders telt <em>AI had gelijk/ongelijk</em> uit het AI-logboek.</li>
            <li>Bovenaan staat een samenvatting (eens, strenger, soepeler, naar de operator, onterechte level-ups) en een lijst om na te kijken.</li>
            <li>Met het vinkje <em>nieuwe rubric</em> stelt de opdracht-assistent eerst een nieuwe rubric voor en test de replay daarmee. De opdrachten zelf veranderen niet.</li>
            <li>De agent doet de replay tussen de echte teamberichten door: een lopend spel gaat voor. Reken op 10 à 15 seconden per teambericht.</li>
            <li>Bedenk bij het lezen: had de AI toen anders gereageerd, dan had het team daarna misschien iets anders geschreven. De replay meet dus per bericht, niet een heel spel.</li>
        </ul>
    </section>

    <section class="card" id="privacy">
        <div class="card-title"><?= icon('lock') ?>Privacy en modellen</div>
        <ul class="plain">
            <li>Naar het AI-model gaan alleen de opdracht, de rubric en de berichten. Geen teamnamen, e-mailadressen, links of namen van operators: in de prompt heet het team “Team”.</li>
            <li>Teksten van teams staan in afgebakende blokken, zodat instructies in een teambericht niet als opdracht aan de AI gelezen worden.</li>
            <li>De AI gebruikt cloudmodellen via Ollama: berichten van teams gaan dus naar die servers. Teams zien bij aanmelden en op hun dashboard dat berichten met AI-hulp verwerkt kunnen worden, en dat ze geen persoonsgegevens moeten delen.</li>
            <li>Standaard draaien de intentie-agent, hulp-agent en beoordelaar op <code>gpt-oss:20b-cloud</code>, en de validator en opdracht-assistent op het grotere <code>gpt-oss:120b-cloud</code>. Beoordelaar en validator gebruiken bewust verschillende modellen, zodat ze niet dezelfde fout maken. De beheerder kan dit wijzigen.</li>
            <li>Is het model niet bereikbaar, dan toont de zijbalk “model niet bereikbaar”. Een beoordeling die daardoor mislukt, wordt “Operator nodig”: de AI doet nooit zelf iets als een model faalt.</li>
        </ul>
    </section>
</div>
<?php include 'admin_footer.php'; ?>
