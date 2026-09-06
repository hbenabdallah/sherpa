# ADR 0003 : PostgreSQL plutôt que MySQL

## Statut

Accepté, mars 2024.

## Contexte

La première version tournait sur MySQL 5.7. Les rapports de ventes demandaient des
requêtes analytiques (fonctions de fenêtrage, CTE récursives) mal servies par cette
version, et les contraintes d'exclusion manquaient pour garantir qu'un stock ne soit
jamais réservé deux fois.

## Décision

Migrer vers PostgreSQL. Les réservations de stock utilisent une contrainte
d'exclusion ; les rapports utilisent des vues matérialisées rafraîchies chaque nuit.

## Conséquences

L'équipe doit maîtriser les spécificités de PostgreSQL (verrous consultatifs,
`VACUUM`). La migration des données a pris deux semaines.
