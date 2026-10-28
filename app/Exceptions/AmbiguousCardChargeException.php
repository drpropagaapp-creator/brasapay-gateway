<?php

namespace App\Exceptions;

/**
 * Cobrança de cartão com resultado desconhecido (timeout/conexão interrompida na adquirente).
 * O fallback para outra adquirente é interrompido para não cobrar o cartão duas vezes; o
 * pedido fica marcado (metadata.card_ambiguous_attempt) para conciliação.
 */
class AmbiguousCardChargeException extends \RuntimeException {}
