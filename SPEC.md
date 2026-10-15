# class-viewer — Spécification

> Outil **standalone** de visualisation et de navigation des classes PHP
> (Symfony/Doctrine) d'un projet, rendu en HTML autonome, léger et fluide.
> Se pose sur n'importe quel projet **sans rien y installer**.

## 1. Problème

Les outils existants affichent **tout** le graphe de classes d'un coup : illisible
et ingérable sur un vrai projet. On veut l'inverse : partir d'une classe et
**naviguer** de proche en proche, avec un niveau de détail qui dépend de la
distance au point de focus.

## 2. Principe central — zoom sémantique (degree-of-interest)

Un seul concept dirige toute l'UX :

- **Nœud focus** (distance 0) : détaillé (nom, namespace, dépendances listées).
- **Voisins directs** (distance 1) : nom de classe seul, cliquables. On affiche
  **les deux sens** : ce que le focus utilise (sortant) **et** ce qui l'utilise
  (entrant), visuellement distingués (ex. sens de la flèche).
- **Distance 2+** : agrégés / estompés (points ou bulles de namespace), peu précis.
- Cliquer sur un nœud **re-centre** le graphe sur lui (il devient le focus).

> Règle : on n'affiche jamais plus que le focus + son voisinage proche. Le reste
> est suggéré, pas rendu en entier. C'est ce qui garde la fluidité sur gros projet.

En plus de la navigation par clic :
- **Recherche** : champ texte avec autocomplétion sur le nom court et le FQCN,
  qui re-centre sur la classe choisie.
- **Vue large / vue rapprochée** : un contrôle de distance (slider ou molette)
  règle le rayon de voisinage affiché. Large = beaucoup de classes, peu de détail ;
  rapproché = peu de classes, plein de détail.

## 3. Périmètre (v1)

### Dans le périmètre
- Langage source : **PHP** (projets Symfony/Doctrine).
- Un **seul type de lien** : **dépendances** (ce qu'une classe utilise). Une
  arête `{from, to}` est émise, en FQCN résolues, dès qu'une classe interne est
  référencée via :
  - un paramètre typé (constructeur ou autre méthode, injection de dépendances),
  - une propriété typée,
  - un type de retour de méthode,
  - une référence dans le corps : `new`, `::class` / `::CONST`, appel statique,
    accès à une propriété statique, `instanceof`,
  - un attribut PHP (`#[...]`) porté par la classe.
  - Les types union, nullables (`?T`) et intersection sont parcourus membre par
    membre ; chaque membre interne donne une arête.
- Ne comptent **pas** comme dépendances : l'héritage (`extends`, `implements`,
  trait `use`) et les auto-références (`self`, `static`, `parent`, ou la classe
  par son propre nom).
- Distribution **standalone** : un seul fichier **PHAR**. On le pose où on veut et
  on le lance contre un projet cible — **aucune installation dans le projet cible**,
  aucun `composer require`.
- Génération **statique** : `php class-viewer.phar <projet>` produit **un seul HTML
  autonome, JSON embarqué dedans**. Double-clic pour l'ouvrir, aucun serveur.
- Rendu **SVG**, JS vanilla, chargement paresseux du voisinage.

### Hors périmètre (v1, à rouvrir plus tard)
- Héritage / interfaces, composition, appels de méthodes (liens non demandés en v1).
- Autres langages.
- Édition, refactoring, navigation vers le code source.

## 4. Architecture

Un seul PHAR qui fait tout : scanner + génération du HTML. Le JSON reste le format
pivot interne, mais il finit **embarqué** dans le HTML de sortie.

```
  projet PHP  ──►  [ class-viewer.phar : scanner + viewer embarqué ]  ──►  graph.html (autonome)
```

### 4.1 Scanner (CLI, PHP)
- **Codé en PHP** : le scanner analyse du PHP, donc on utilise **nikic/php-parser**
  et surtout son **`NameResolver`**, qui résout automatiquement les `use` et noms
  courts en FQCN — c'est le point dur du projet, résolu gratuitement. Le coder dans
  un autre langage obligerait à re-résoudre les noms à la main avec un parser moins
  fidèle : non.
- Parcourt les fichiers `.php`, construit pour chaque classe/interface/enum :
  son FQCN, son namespace, son fichier, et la liste des FQCN dont elle dépend.
- Ne garde que les dépendances **internes au projet** — une arête n'est produite
  que si sa cible (`to`) est l'`id` d'un nœud du projet ; les classes vendor et
  natives PHP, sans nœud, sont exclues de fait (filtrable plus tard).
- Dédoublonne les arêtes (un même couple `{from, to}` atteint par plusieurs
  références ne donne qu'une arête) et exclut les auto-références (`from == to`).
- Trie les arêtes par `(from, to)` (sortie déterministe, rejouable).
- Écrit `graph.json`.
- Prérequis d'exécution : PHP + Composer (pour *lancer* le scan uniquement ; le
  viewer, lui, n'a besoin de rien).

### 4.2 Format pivot — `graph.json`
```json
{
  "version": 1,
  "nodes": [
    { "id": "App\\Service\\Foo", "name": "Foo", "ns": "App\\Service", "kind": "class", "file": "src/Service/Foo.php" }
  ],
  "edges": [
    { "from": "App\\Service\\Foo", "to": "App\\Repository\\BarRepository" }
  ]
}
```
- `id` = FQCN (clé unique). `kind` ∈ `class | interface | enum | trait`.
- `external: true` (optionnel) marque un nœud hors du code du projet (vendor), présent
  seulement si `--include-vendor` est passé. Le viewer le rend distinctement (grisé,
  non navigable).
- Le format est **le contrat** : n'importe quel autre scanner (autre langage) peut
  produire le même JSON et alimenter le même viewer.

### 4.3 Viewer (HTML autonome)
- Un seul fichier HTML, JS/CSS **inlinés**, et le `graph.json` **embarqué** dans
  une balise `<script type="application/json">` (donc pas de `fetch`, ouvrable en
  `file://` par double-clic).
- Garde tout le graphe en mémoire (index par `id`) mais **ne rend que le voisinage
  du focus** → c'est ça, le "cache" et la légèreté.
- Layout : positionnement des quelques nœuds affichés (force locale sur le petit
  sous-graphe, pas sur le graphe entier).
- Interactions : clic = re-centrer, recherche = re-centrer, contrôle de distance,
  pan/zoom basique.

## 5. Contraintes non fonctionnelles
- **Standalone** : un seul PHAR, posé n'importe où, lancé contre n'importe quel projet.
  Rien installé dans le projet cible, aucune config requise.
- **Léger** : viewer sans gros framework. Un seul petit module de layout au besoin.
- **Fluide** : rendu d'un voisinage (quelques dizaines de nœuds max), jamais du graphe complet.
- **Autonome en sortie** : le HTML (JSON embarqué) s'ouvre par double-clic, sans serveur.
- **Rejouable** : relancer le PHAR régénère le HTML ; rien d'autre à faire.

## 6. Critères d'acceptation (v1)
1. `php class-viewer.phar <projet>` produit un `graph.html` autonome, sans rien
   écrire ni installer dans le projet cible.
2. Ouvrir ce HTML par double-clic (en `file://`) centre sur une classe et affiche
   ses dépendances directes, sans serveur.
3. Cliquer sur un voisin re-centre le graphe sur lui en < 1 s, sans recharger la page.
4. La recherche trouve une classe par nom court ou FQCN et re-centre dessus.
5. Le contrôle de distance élargit/resserre le voisinage affiché.
6. Un projet de plusieurs centaines de classes reste fluide (on n'affiche jamais tout).

## 7. Décisions tranchées
- **Langage scanner** : PHP (nikic/php-parser + `NameResolver`). Viewer en JS vanilla.
- **Contenu du focus** : dépendances uniquement (pas de méthodes/propriétés) → JSON minimal.
- **Layout** : `d3-force` seul (module isolé, ~30 ko).
- **Voisinage** : les deux sens à distance 1 (entrant + sortant), distingués visuellement.

- **Distribution** : un seul **PHAR** standalone. Sortie = un seul HTML autonome
  (JSON embarqué), ouvrable par double-clic sans serveur.
- **Vendor** : exclu par défaut. Option `--include-vendor` ; dans ce cas les nœuds
  vendor sont marqués `external: true` et rendus distinctement (grisés, non navigables).
- **PHP cible** : PHAR sous PHP 8.1+, analysant tout PHP que nikic sait parser.

## 8. Décisions encore ouvertes
- **g. Build du PHAR** : via `box` (standard, mais ajoute un outil de build au repo perso).
  Non bloquant : en dev on lance `php bin/class-viewer` directement, le PHAR ne sert
  qu'à la distribution finale.
