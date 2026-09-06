# API : authentification

## Jetons d'accès

L'API publique s'authentifie par jeton JWT. Un jeton d'accès expire au bout de
**15 minutes** ; le client l'obtient en échange de sa clé API sur `POST /auth/token`.

## Renouvellement

Un jeton de rafraîchissement, valable **30 jours**, permet d'obtenir un nouveau
jeton d'accès sans renvoyer la clé API. Il est révoqué à chaque changement de mot
de passe du compte.

## Limites de débit

Chaque clé API est limitée à **100 requêtes par minute**. Au-delà, l'API répond
`429 Too Many Requests` avec un en-tête `Retry-After` en secondes.

## Codes d'erreur

| Code | Signification |
| --- | --- |
| AUTH-001 | Clé API inconnue |
| AUTH-002 | Jeton expiré |
| AUTH-003 | Signature du jeton invalide |
| AUTH-004 | Compte suspendu |
