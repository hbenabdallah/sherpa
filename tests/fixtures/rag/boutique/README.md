# Boutique

Application de vente en ligne de matériel de randonnée : catalogue, panier, paiement,
facturation et suivi des expéditions.

## Installation

```bash
make install
make fixtures
```

L'application écoute sur le port 8080. Les fixtures créent un compte administrateur
`admin@boutique.test` dont le mot de passe est affiché à la fin de la commande.

## Lancer les tests

La suite complète se lance avec `make test`. Les tests d'intégration ont besoin
d'une base PostgreSQL : `make test-integration` démarre un conteneur dédié.

## Documentation

Le dossier `docs/` contient l'architecture, les règles métier et les procédures.
