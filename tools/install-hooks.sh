#!/bin/sh
# Instala los hooks de git del proyecto (copia tools/pre-commit a .git/hooks/).
cd "$(dirname "$0")/.." || exit 1
cp tools/pre-commit .git/hooks/pre-commit && chmod +x .git/hooks/pre-commit && echo "Hook pre-commit instalado (batería de NCS)."
