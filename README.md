# Upsidly Agent — plugin WordPress

Assistant IA dans l'administration WordPress. L'utilisateur choisit une skill (« Rédiger un article », « Créer ou modifier une page »), remplit un court formulaire et reçoit un **brouillon** prêt à relire.

```
Plugin WordPress (ce dépôt)  →  Proxy Upsidly (api.upsidly.app)  →  API Claude (Anthropic)
```

- Le plugin ne parle **qu'au proxy**, de serveur à serveur. La clé de site n'est jamais envoyée au navigateur.
- Rien n'est jamais publié automatiquement : tout est créé en brouillon. Une révision de page n'est appliquée que sur clic, avec une révision WordPress pour revenir en arrière.
- Le contenu reçu est toujours nettoyé (blocs autorisés + `wp_kses_post`) avant insertion.
- Capacités WordPress respectées (`edit_posts`, `edit_pages`, `publish_pages`, `manage_options`).

Prérequis : WordPress 6.4+, PHP 8.0+. Licence GPL-2.0-or-later.

## Installation

1. Télécharger `upsidly-agent.zip` depuis la dernière [release](https://github.com/upsidly/upsidly-agent/releases).
2. Extensions → Ajouter → Téléverser une extension → activer.
3. Upsidly Agent → Réglages : coller la clé de site `upa_…` fournie par Upsidly, renseigner l'activité du site et le ton de marque.

Les mises à jour sont ensuite proposées par WordPress à chaque nouvelle release (Plugin Update Checker).

### Constantes (wp-config.php)

| Constante | Rôle |
|---|---|
| `UPSIDLY_AGENT_KEY` | Impose la clé de site (le champ de réglage devient non modifiable) |
| `UPSIDLY_AGENT_API_URL` | URL du proxy (défaut `https://api.upsidly.app`) |
| `UPSIDLY_AGENT_FAKE_PROXY` | Développement : réponses factices, sans consommer de crédits (ignorée si `WP_DEBUG` est faux) |

En mode factice, les erreurs se simulent en saisissant comme sujet (ou brief) : `__erreur_quota__`, `__erreur_cle__`, `__erreur_site__`, `__erreur_service__`, `__erreur_incomplet__`, `__erreur_saisie__`.

## Fonctionnement

- **Connexion** : à l'enregistrement de la clé, au clic sur « Tester la connexion » et une fois par jour (cron `upsidly_agent_daily_connect`), le plugin appelle `POST /v1/connect` avec : nom du site, versions (plugin, WordPress, PHP), plugin SEO détecté, éditeur de page, multisite. Aucune donnée personnelle. À la désactivation ou au retrait de la clé : `POST /v1/disconnect`.
- **Assistant** (menu Upsidly Agent) : jauge de crédits, choix de la skill, formulaire généré depuis le schéma renvoyé par le proxy, brouillon créé, aperçu, différences et application pour une modification de page.
- **Pages Elementor** : listées mais non modifiables (refus côté serveur).
- **Méta-description** : écrite dans Rank Math, Yoast SEO ou SEOPress (tous ceux détectés), sinon dans `_upsidly_agent_meta_description`.
- **Historique** : journal local (table `{prefix}upsidly_agent_log`), sans les entrées ni le contenu généré.
- **Désinstallation** : options, transient, table et cron supprimés ; brouillons, pages et métas `_upsidly_agent_*` conservés.

## Développement

```bash
npm ci && npm run build        # interface React (build/)
npm run lint:js
composer install               # PHPUnit, WordPress Coding Standards
vendor/bin/phpcs
```

Tests d'intégration WordPress (MySQL requis) :

```bash
bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest
vendor/bin/phpunit
```

Avec Docker : `npx wp-env start` (voir `.wp-env.json`, proxy factice activé).

La CI GitHub Actions lance PHPUnit (PHP 8.0 + WordPress 6.4, PHP 8.3 + dernière version de WordPress), phpcs, le lint JS, le build et une vérification de syntaxe en PHP 8.0.

## Publier une version

1. Mettre à jour la version dans l'en-tête de `upsidly-agent.php` et dans `UPSIDLY_AGENT_VERSION` (et `package.json`).
2. Commiter, puis pousser un tag `vX.Y.Z`.
3. Le workflow `release.yml` construit `upsidly-agent.zip` (avec `build/` et `vendor/plugin-update-checker/`, sans `src/`, `tests/` ni fichiers de développement) et l'attache à la release GitHub.

Le JSX est compilé en mode classique (`createElement`) pour rester compatible avec WordPress 6.4, où le script `react-jsx-runtime` n'existe pas encore.
