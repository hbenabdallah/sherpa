# Conventions de code

## Style

Le code suit PSR-12. `make lint` lance PHP-CS-Fixer et PHPStan au niveau 8 ; la CI
refuse toute fusion qui ne passe pas.

## Nommage

Les cas d'usage sont nommés à l'impératif (`PasserCommande`, `RembourserArticle`).
Les événements sont au participe passé (`CommandePassée`).

## Commits et branches

Les messages de commit suivent Conventional Commits (`feat:`, `fix:`, `chore:`).
Les branches sont nommées `type/numéro-du-ticket-description`, par exemple
`fix/482-arrondi-tva`.

## Revue de code

Toute modification passe par une merge request relue par au moins une autre
personne. Les modifications de la facturation demandent deux relectures.
