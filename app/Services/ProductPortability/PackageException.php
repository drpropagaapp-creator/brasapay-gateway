<?php

namespace App\Services\ProductPortability;

use RuntimeException;

/** Pacote inválido, adulterado ou fora dos limites — mensagem segura para o usuário. */
class PackageException extends RuntimeException {}
