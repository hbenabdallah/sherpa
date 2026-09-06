# Architecture

## Vue d'ensemble

L'application suit une architecture hexagonale. Le domaine (`src/Domain`) ne dépend
d'aucun framework ; les adaptateurs (`src/Infrastructure`) implémentent ses ports.

## Modules

- **Catalogue** : produits, variantes, stocks.
- **Commande** : panier, validation, paiement.
- **Facturation** : factures, avoirs, TVA.
- **Expédition** : transporteurs, étiquettes, suivi.

Chaque module expose ses cas d'usage sous forme de commandes et de requêtes
(CQRS léger, sans bus d'événements distribué).

## Stockage

La base principale est PostgreSQL 16. Le cache applicatif et les sessions sont
dans Redis. Les fichiers (étiquettes PDF, factures) sont stockés sur un bucket S3
compatible, jamais sur le disque du serveur.

## Tâches asynchrones

Les envois d'e-mails et la génération des factures passent par Symfony Messenger,
avec un transport Redis. Les workers sont supervisés par systemd et redémarrés
après 500 messages traités pour éviter les fuites de mémoire.
