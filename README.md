# class-viewer

Outil standalone de visualisation et de navigation du graphe de dépendances des
classes PHP d'un projet. Pour la vision complète de la cible, voir [SPEC.md](SPEC.md).

## État actuel

Le binaire `bin/class-viewer` **extrait les nœuds et les arêtes de dépendance du
graphe de classes** d'un projet cible, sans rien écrire ni installer dans le
projet cible. Il découvre récursivement les fichiers `.php`, les parse avec
nikic/php-parser (`NameResolver`) et construit un graphe de la forme
`{ "version": 1, "nodes": [...], "edges": [...] }` (voir le format en SPEC.md §4.2).

Par défaut, `php bin/class-viewer <projet>` **écrit un fichier `graph.html`
autonome** dans le répertoire courant (jamais dans le projet cible) :

- l'emplacement est configurable avec `--output <chemin>` (alias `-o`) ; les
  répertoires parents manquants sont créés. Si l'écriture échoue (chemin invalide,
  droits insuffisants…), la commande affiche un message d'erreur sur stderr et
  sort en échec (exit ≠ 0) sans créer de fichier partiel ;
- le HTML est **autonome** : CSS et JS sont inlinés, aucune référence externe
  (`http(s)://`, `<link>`, `src=`, `fetch`/XHR). Le `graph.json` est embarqué dans
  une balise `<script type="application/json">`, ouvrable directement en `file://`
  par double-clic, sans serveur ;
- le JSON embarqué est identique à celui produit par `--json`, avec les `<`
  échappés (`<`) pour qu'un contenu tel que `</script>` ne rompe pas la balise ;
- le **viewer** actuel est minimal (JS vanilla) : il lit le JSON embarqué et
  affiche le nombre de nœuds, le nombre d'arêtes et la liste des FQCN des nœuds.
  Le rendu graphique riche (SVG, ego-graph, interactions) viendra plus tard ;
- avec `--json`, le binaire conserve l'ancien comportement : il imprime le
  `graph.json` (pretty-print) sur **stdout** et n'écrit aucun fichier.

- chaque déclaration `class` / `interface` / `enum` / `trait` d'un fichier donne
  un nœud `{ id, name, ns, kind, file }` où `id` est la FQCN pleinement résolue
  (namespace inclus ; `ns` vide pour le namespace global) et `file` le chemin
  relatif au projet cible ;
- chaque dépendance interne d'un nœud donne une arête `{ from, to }` en FQCN
  résolues, où `from` est la classe qui dépend et `to` l'`id` d'un nœud du
  projet ; une arête est émise pour un paramètre typé (constructeur ou autre
  méthode), une propriété typée, un type de retour de méthode, une référence de
  corps (`new`, `::class` / `::CONST`, appel statique, propriété statique,
  `instanceof`) ou un attribut de classe (`#[...]`) ; les types union, nullables
  et intersection sont parcourus membre par membre ;
- par défaut, seules les dépendances **internes** sont gardées : une référence vers
  une classe vendor ou native PHP (sans nœud du projet) ne produit ni nœud ni arête ;
  avec `--include-vendor`, chaque FQCN hors projet réellement référencée (vendor **et**
  natifs PHP) devient un nœud marqué `external: true` et les arêtes vers elle sont
  conservées (voir « Classes externes » plus bas) ;
- l'héritage (`extends`, `implements`, trait `use`) ne produit **aucune** arête,
  même vers une classe interne ;
- les auto-références sont exclues (`self`, `static`, `parent`, ou la classe par
  son propre nom → pas d'arête `from == to`) ;
- les arêtes sont dédoublonnées (un même couple `{ from, to }` atteint par
  plusieurs références ne compte qu'une fois) et triées par `(from, to)`
  croissant (sortie déterministe) ;
- les nœuds sont triés par `id` croissant (sortie déterministe) ;
- les classes anonymes et les fichiers sans déclaration nommée ne produisent
  aucun nœud ;
- un fichier non parsable est ignoré avec un avertissement sur stderr, exit 0 ;
- une FQCN déclarée plusieurs fois n'est retenue qu'une fois (la première dans
  l'ordre de scan, soit l'ordre alphabétique des chemins relatifs), avec un
  avertissement sur stderr ;
- tout dossier `vendor/` est exclu, à n'importe quelle profondeur (un fichier
  nommé `vendor.php` reste pris en compte) ; seule l'extension `.php`
  (minuscules) est reconnue ;
- un chemin absent ou qui n'est pas un répertoire provoque une sortie en erreur
  (exit ≠ 0, message sur stderr) ; l'absence d'argument affiche l'usage.

### Classes externes (`--include-vendor`)

Sans le drapeau, les classes hors projet sont invisibles (ni nœud, ni arête). Avec
`--include-vendor`, chaque FQCN hors projet réellement référencée est matérialisée
comme nœud external. Aucun scan de `vendor/` n'est effectué : seules les classes
citées par le code du projet apparaissent, et leur vrai type (`class`/`interface`/…)
ou fichier n'est pas résolu (pas de classmap).

Un nœud external reprend le format du viewer plus un champ `external: true` :

- `id` — la FQCN référencée ;
- `name` — dernier segment de la FQCN ;
- `ns` — préfixe de namespace (vide pour une classe native sans namespace) ;
- `kind` — `"class"` par défaut (type réel inconnu sans parser le vendor) ;
- `file` — `""` ;
- `external` — `true`.

Les nœuds du projet ne portent **pas** de champ `external` (absent, pas `false`).
Les nœuds external sont mêlés aux internes dans la liste unique triée par `id`.

```json
{ "id": "Psr\\Log\\LoggerInterface", "name": "LoggerInterface", "ns": "Psr\\Log", "kind": "class", "file": "", "external": true }
```

À venir (jalons suivants, cf. SPEC.md) : rendu graphique riche du viewer et build du PHAR.

## Prérequis

Aucun PHP ni Composer sur l'hôte : **tout passe par Docker**. L'image dev
`class-viewer-dev:issue-1` (PHP 8.5 + pcov + Composer) est pilotée par le `Makefile`.

## Installer et lancer (dev)

```sh
make build                     # construire l'image dev
make install                   # installer les dépendances (composer install)
make run ARGS="<chemin>"       # écrire un graph.html autonome (dans le cwd du conteneur)
make run ARGS="<chemin> --output <fichier.html>"   # écrire le HTML à cet emplacement
make run ARGS="<chemin> --json"             # imprimer le graph.json sur stdout (pas de fichier)
make run ARGS="<chemin> --include-vendor"   # + nœuds external pour les classes hors projet
```

Exemple :

```sh
make run ARGS="tests/fixtures/graph/nominal --json" | jq .
```

Sortie type (`--json`) :

```json
{
    "version": 1,
    "nodes": [
        { "id": "App\\Service\\Foo", "name": "Foo", "ns": "App\\Service", "kind": "class", "file": "src\/Service\/Foo.php" }
    ],
    "edges": [
        { "from": "App\\Service\\Foo", "to": "App\\Repository\\BarRepository" }
    ]
}
```

## Garde-fous

```sh
make check        # cs + stan + test
make infection    # tests de mutation (MSI 100)
```

Cibles unitaires également disponibles : `make test` (PHPUnit), `make stan`
(PHPStan, level max), `make cs` / `make cs-fix` (PHP-CS-Fixer, PSR-12 + strict_types).

## Structure

- `bin/class-viewer` — bootstrap mince de l'application console.
- `src/` — logique (PSR-4 `ClassViewer\`) :
  - `src/Command/ScanCommand.php` — commande `scan`, par défaut (glue : lit
    l'argument `path` et les options `--include-vendor`, `--json`,
    `--output`/`-o` ; appelle le builder, écrit les avertissements sur stderr ;
    par défaut rend le `graph.html` via `HtmlRenderer` et l'écrit au chemin voulu
    — échec propre si l'écriture échoue — ou, avec `--json`, imprime le JSON sur
    stdout) ;
  - `src/Html/HtmlRenderer.php` — rend le document HTML autonome : CSS/JS inlinés,
    `graph.json` embarqué dans `<script type="application/json">` (avec `<`
    échappé en `<`) et viewer vanilla JS minimal qui lit ce JSON et affiche
    compteurs + liste des FQCN ;
  - `src/Graph/GraphBuilder.php` — découverte des fichiers, orchestration,
    déduplication des FQCN et tri des nœuds ; résolution des arêtes (filtre
    interne sur l'ensemble des nœuds, dédoublonnage, exclusion des
    auto-références, tri par `(from, to)`) ; avec `--include-vendor`, matérialise
    les FQCN référencées hors projet en nœuds `external: true` ; renvoie un
    `BuildResult` (`graph` + `warnings`) ;
  - `src/Graph/NodeExtractor.php` — parse un fichier via nikic/php-parser et le
    `NameResolver` ; expose `extract()` (nœuds) et `extractEdges()` (arêtes
    candidates brutes) ;
  - `src/Graph/NodeCollectingVisitor.php` — collecte les déclarations nommées en
    nœuds pendant la traversée de l'AST ;
  - `src/Graph/DependencyCollectingVisitor.php` — collecte les arêtes candidates
    (FQCN résolues) depuis les types, références de corps et attributs ; ne
    visite jamais l'héritage et écarte `self`/`static`/`parent`. Le filtrage
    interne, le dédoublonnage et l'exclusion des auto-références se font dans
    `GraphBuilder`.
- `tests/` — tests PHPUnit (PSR-4 `ClassViewer\Tests\`) et fixtures (fixtures de
  graphe sous `tests/fixtures/graph/`).

Dépendance runtime : **nikic/php-parser** (parsing PHP et résolution des FQCN).
