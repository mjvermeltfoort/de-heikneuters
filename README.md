# C.V. De Heikneuters

Eerste versie van de nieuwe website van C.V. De Heikneuters uit Nijnsel.

## Stack

- Astro
- Statische build, geschikt voor STRATO
- Google Calendar en Facebook kunnen in een volgende stap als databron worden gekoppeld

## Ontwikkelen

```bash
npm install
npm run dev
```

## Build

```bash
npm run build
```

De statische site wordt gegenereerd in `dist/`.


## Deployment

Pushes naar `main` worden na een geslaagde Astro-build via SFTP naar STRATO gedeployed wanneer de `STRATO_SFTP_*` repository secrets zijn ingesteld.
