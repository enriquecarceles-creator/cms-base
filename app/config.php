<?php
// Ajustes básicos. Los datos de empresa (teléfono, dirección, email…) se editan desde el panel.
// Muestra «Empezar de cero» (vaciar el catálogo) en Web → Empresa. Déjalo en false en webs en producción.
const ALLOW_RESET = false;
const MAX_UPLOAD_BYTES = 8 * 1024 * 1024; // 8 MB por foto
const SCHEMA_VERSION = 25;

// El importador solo descarga de direcciones públicas de internet.
// Déjalo en false (solo se pone true en pruebas locales).
const IMPORT_ALLOW_LOCAL = false;
