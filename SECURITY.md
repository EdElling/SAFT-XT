# Security Policy · Sicherheitsrichtlinie

**English** · [Deutsch](#deutsch)

## Supported versions

Security fixes are provided for the **latest release** of SAFT. Operators should stay on the latest release; the
update procedure is described in the [production installation guide](docs/manual/INSTALL_PRODUCTION.md#9-installing-updates).

## Reporting a vulnerability

Please do **not** open a public issue. Report suspected vulnerabilities by e-mail to
**ekkehard.spilling@gmx.net** with a description, the affected version and, if possible, steps to reproduce. You will
receive an acknowledgement within one week.

## How dependencies are kept secure

- `composer.lock` pins every dependency; servers only receive new versions through a tested SAFT release.
- GitHub **Dependabot alerts** report known vulnerabilities in dependencies; **Dependabot security updates** open a
  pull request with the fix right away; weekly **version updates** bundle compatible updates in one pull request.
- The **Security audit** workflow runs `composer audit` and `npm audit` every week and on every lock file change.
- Every dependency pull request is tested before it is merged (feature tests; browser tests for larger updates).
  A security fix that affects SAFT is released as a patch release within days and marked as such in the release
  notes. Compatible updates are released monthly; major versions (e.g. Laravel, Filament) are planned separately.

Details: [system administration guide, "Updates and security"](docs/manual/SYSADMIN_GUIDE.md#updates-and-security).

---

## Deutsch

### Unterstützte Versionen

Sicherheitskorrekturen gibt es für das **jeweils neueste Release** von SAFT. Betreiber sollten auf dem neuesten
Release bleiben; das Einspielen beschreibt die [Installationsanleitung für Produktionsserver](docs/manual/INSTALL_PRODUCTION.de.md#9-updates-einspielen).

### Sicherheitslücke melden

Bitte **kein** öffentliches Issue anlegen. Vermutete Lücken per E-Mail an
**ekkehard.spilling@gmx.net** melden, mit Beschreibung, betroffener Version und wenn möglich den Schritten zum Nachvollziehen. Eine
Eingangsbestätigung kommt innerhalb einer Woche.

### Wie die Abhängigkeiten sicher gehalten werden

- `composer.lock` legt jede Abhängigkeit fest; Server erhalten neue Versionen nur mit einem getesteten SAFT-Release.
- GitHub **Dependabot alerts** melden bekannte Lücken in Abhängigkeiten; **Dependabot security updates** öffnen sofort
  einen Pull-Request mit der Korrektur; wöchentliche **Versions-Updates** bündeln kompatible Updates in einem
  Pull-Request.
- Der Workflow **Security audit** führt jede Woche und bei jeder Änderung der Lock-Dateien `composer audit` und
  `npm audit` aus.
- Jeder Pull-Request mit Abhängigkeiten wird vor dem Zusammenführen getestet (Feature-Tests; bei größeren Updates
  auch Browser-Tests). Eine Sicherheitskorrektur, die SAFT betrifft, erscheint innerhalb weniger Tage als
  Patch-Release und ist in den Release-Notes als solche gekennzeichnet. Kompatible Updates erscheinen monatlich;
  Hauptversionen (z. B. Laravel, Filament) werden gesondert geplant.

Einzelheiten: [Handbuch Systemadministration, „Updates und Sicherheit“](docs/manual/SYSADMIN_GUIDE.de.md#updates-und-sicherheit).
