Déploiement
===========

Le déploiement est automatisé par la CI : chaque fusion sur la branche ``main``
produit une image taguée avec le hash du commit.

Fenêtres de mise en production
------------------------------

On ne met en production que le mardi et le jeudi, entre 10 h et 16 h. Aucune mise en
production le vendredi ni la veille d'un jour férié.

Retour arrière
--------------

En cas d'incident après une mise en production, on redéploie l'image précédente avec
``make rollback``. La commande garde les migrations de base : une migration qui
détruit des données doit donc être découpée en deux mises en production.

Drapeaux de fonctionnalité
--------------------------

Les nouvelles fonctionnalités sont livrées désactivées derrière un drapeau
(``config/features.yaml``) et activées progressivement, d'abord pour les comptes
internes.
