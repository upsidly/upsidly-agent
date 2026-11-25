# Upsidly Agent — connecteur WordPress

Relie un site WordPress au tableau de bord **Upsidly** (`www.upsidly.app/fr/dashboard`). Tout se pilote depuis le tableau de bord : agent IA (diagnostic, modifications validées une par une, sauvegardes, annulation) et rédaction de contenus (articles, types sur mesure, produits WooCommerce, champs ACF / JetEngine).

L'extension n'a **aucune interface** dans WordPress : un bouton « Relier à Upsidly » (page Extensions et notice du tableau de bord), puis l'état de la liaison sous le nom de l'extension.

## Liaison

1. Extensions → « Relier à Upsidly » → approbation sur le tableau de bord (compte Upsidly connecté).
2. Retour sur WordPress avec un code à usage unique, échangé serveur à serveur (`POST https://api.upsidly.app/v1/connector/claim`) contre un secret partagé, stocké chiffré (libsodium, clé dérivée des clés de WordPress).
3. Les actions demandées depuis le tableau de bord s'exécutent avec les droits de l'administrateur qui a relié le site.

## Demandes d'Upsidly

`POST /wp-admin/admin-ajax.php?action=upsidly_connector`, corps JSON `{ "op": … }`, en-têtes `X-Upsidly-Timestamp`, `X-Upsidly-Nonce`, `X-Upsidly-Signature` = `hex(HMAC-SHA256(secret, "POST\n{ts}\n{nonce}\n" . sha256(corps)))` (±5 min, nonce à usage unique). Exécutées dans le contexte de l'administration (mises à jour des extensions premium visibles).

| Opération | Rôle |
|---|---|
| `ping` | Version, environnement (WordPress, PHP, extensions, thème) |
| `tools.run` | Outils demandés par l'agent : lecture exécutée, écriture en attente avec aperçu (niveau d'agent transmis par Upsidly) |
| `actions.list` / `actions.decide` / `actions.undo` / `actions.undo_all` / `actions.stop` | Actions de la conversation, validation ou refus, annulation (sauvegarde restaurée), arrêt |
| `content.catalog` / `content.prepare` / `content.save` | Rédaction : types et taxonomies, entrées complétées pour l'IA, enregistrement du résultat nettoyé |

## Développement

```bash
composer install
bin/install-wp-tests.sh wordpress_test root '' localhost latest
vendor/bin/phpunit
vendor/bin/phpcs
```

Versions publiées par tag `vX.Y.Z` (ZIP `upsidly-agent.zip` en release GitHub, mises à jour via Plugin Update Checker).
