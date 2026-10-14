# class-viewer — conventions projet

Outil standalone de visualisation du graphe de dépendances des classes PHP.
La spécification fait foi : voir [SPEC.md](SPEC.md).

Les standards globaux (PSR-12, SOLID, TDD, workflow worktree) s'appliquent et ne sont
pas répétés ici. Ce fichier ne contient que ce qui est propre au projet.

## Environnement

Pas de PHP/Composer sur l'hôte : **tout passe par Docker**, via l'image dev
`class-viewer-dev` (PHP 8.5 + pcov + Composer), pilotée par le `Makefile`.

- `make build` — construire l'image dev
- `make install` — installer les dépendances (`composer install`)
- `make test` — tests PHPUnit
- `make cs` / `make cs-fix` — PHP-CS-Fixer (vérifier / corriger)
- `make stan` — PHPStan (level max)
- `make infection` — tests de mutation (Infection, MSI 100)
- `make check` — garde-fous : cs + stan + test
- `make run ARGS="<chemin>"` — lancer le binaire contre un projet cible

> Le nom de l'image est suffixé par worktree (`class-viewer-dev:issue-<n>`) pour
> l'isolation. Aucun service ni port exposé (CLI pure) → pas de `P` au registre.

## Contraintes

- PHP cible : **>= 8.5** (`config.platform.php` fige la résolution Composer en 8.5).
- CLI bâtie sur **symfony/console** 7.x.
- `composer.lock` est **commité** (outil distribué → installs reproductibles).
- `bin/class-viewer` reste mince (bootstrap) ; toute la logique vit dans `src/`
  (PSR-4 `ClassViewer\`), les tests dans `tests/` (`ClassViewer\Tests\`).

## Git / forge

- Forge : GitHub (`nmoral-atecna/class-viewer`), branche par défaut `main`.
- Branches : `feat/<numéro-issue>-<slug>`.
- Intégration de `main` : **rebase**. Merge des PR : **merge commit**.
