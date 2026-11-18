# MCP da agência (MetaTrack): contas, produtos e importação de pacotes

Endereço: `https://brasapay.com.br/mcp` (JSON-RPC via POST, protocolo MCP).

## Chave de integração

Criada no servidor, uma vez, por um admin da plataforma:

```bash
docker exec -it getfy-app-1 php artisan mcp:platform-key create --name=MetaTrack --admin=EMAIL_DO_ADMIN
docker exec -it getfy-app-1 php artisan mcp:platform-key list
docker exec -it getfy-app-1 php artisan mcp:platform-key revoke --id=ID
```

- A chave (`mcp_pk_…`) aparece **uma vez**. Guarde no cofre de segredos do sistema que vai usar.
- Envie em `Authorization: Bearer <chave>`. No conector MCP da API da Anthropic, use `authorization_token`.
- A chave age em nome do admin que a criou e para de funcionar se ele deixar de ser admin.
- Tudo o que ela faz vai para a auditoria da plataforma.
- **Escopo agência:** leitura e criação. Ficam de fora:
  - saques e reembolsos;
  - KYC e MED;
  - prévias e confirmações de ações.
- Os nomes de ferramenta usam `_` no lugar de `.`, por exemplo `platform_create_seller` e `seller_catalog_create_product`.

## Ferramentas

| Ferramenta | O que faz |
|---|---|
| `platform_create_seller` | Cria a conta do cliente, já liberada para vender. Envia ao cliente o e-mail para criar a senha. O saque continua exigindo KYC, feito pelo cliente. Se o e-mail já existe, devolve a conta existente. |
| `platform_import_product_package` | Importa um zip com manifesto para a conta (`tenant_id`). Roda em segundo plano e devolve `import_id`. |
| `platform_import_status` | Mostra a etapa atual, os produtos criados (ids e links de checkout), os avisos e o erro, se houver. |
| `seller_catalog_*`, `seller_members_*`, `seller_students_*` | As mesmas ferramentas de catálogo e área de membros do MCP do vendedor, executadas na conta indicada em `tenant_id`. Produto criado por aqui já nasce aprovado. |
| `platform_overview`, `platform_kpis`, `platform_list_orders`, `platform_get_order`, `platform_list_sellers`, `platform_get_seller`, `platform_list_pending_approvals`, `platform_payment_health` | Consultas. |

## Pacote (zip)

### Arquivos aceitos

| Tipo | Extensões |
|---|---|
| PDF | `.pdf` |
| Vídeo | `.mp4`, `.m4v`, `.webm` |
| Imagem | `.jpg`, `.jpeg`, `.png`, `.webp`, `.gif` |
| Manifesto | `produto.json` (ou `brasapay.json`, `produtos.json`, `manifest.json`) |

Outros arquivos são ignorados.

### Limites

| Item | Limite |
|---|---|
| Download | 6 GB |
| Conteúdo descompactado | 8 GB |
| Arquivos por zip | 3000 |
| Produtos por pacote | 50 |

### Como enviar

- `package_url` precisa ser **https** e público (pode ser um link assinado do storage, válido por pelo menos 1 hora). Não pode haver redirecionamento.
- O manifesto pode vir na chamada (`manifest`) ou dentro do zip. Os caminhos de arquivo são relativos à raiz do zip. Se o zip vier dentro de uma pasta única, pode omitir o nome dessa pasta.

### Manifesto

```json
{
  "produtos": [
    {
      "nome": "Curso Finanças do Zero",
      "preco": 197.00,
      "descricao": "Aprenda a organizar suas finanças.",
      "categoria": "Finanças",
      "capa": "capas/curso.jpg",
      "ofertas": [
        { "nome": "Black Friday", "preco": 97.00 }
      ],
      "order_bumps": [
        {
          "produto": "Planilha de Orçamento",
          "titulo": "Leve a planilha junto",
          "chamada": "Sim, quero a planilha",
          "descricao": "Planilha pronta para usar.",
          "preco": 19.90
        }
      ],
      "area_membros": {
        "secoes": [
          {
            "titulo": "Curso",
            "modulos": [
              {
                "titulo": "Módulo 1 - Começando",
                "aulas": [
                  { "titulo": "Boas-vindas", "arquivo": "modulo1/01-boas-vindas.mp4" },
                  { "titulo": "Material de apoio", "arquivo": "modulo1/apostila.pdf" },
                  { "titulo": "Grupo de alunos", "url": "https://t.me/grupo", "tipo": "link" },
                  { "titulo": "Recado", "texto": "Assista na ordem." }
                ]
              },
              { "titulo": "Módulo 2", "pasta": "modulo2" }
            ]
          }
        ]
      }
    },
    {
      "nome": "Planilha de Orçamento",
      "preco": 29.90,
      "capa": "capas/planilha.png",
      "area_membros": {
        "modulos": [
          { "titulo": "Download", "aulas": [ { "titulo": "Planilha", "arquivo": "planilha/orcamento.pdf" } ] }
        ]
      }
    }
  ]
}
```

#### Campos do produto

| Campo | Obrigatório | Valores |
|---|---|---|
| `nome` | sim | texto |
| `preco` | sim | número em reais |
| `tipo` | não | `area_membros` (padrão), `link`, `link_pagamento`, `aplicativo` ou `area_membros_externa` |
| `cobranca` | não | `one_time` (padrão) ou `subscription` |
| `descricao`, `categoria`, `email_suporte` | não | texto |

#### Área de membros

- **Aula com `arquivo`:** um `.mp4` vira aula de vídeo e um `.pdf` vira aula de PDF. O arquivo é guardado no storage da plataforma.
- **Aula com `url`:** usa o tipo informado em `tipo` (`video`, `link` ou `pdf`). Sem `tipo`, vira `link`.
- **Aula com `texto`:** vira aula de texto.
- **Módulo com `pasta`:** cria uma aula para cada PDF ou vídeo daquela pasta do zip, na ordem natural do nome do arquivo. O título sai do nome do arquivo.
- **`area_membros.modulos` sem `secoes`:** cria uma seção "Conteúdo".

#### Order bumps

`order_bumps[].produto` aponta para o **nome** de outro produto do mesmo pacote ou para o id de um produto que já existe na conta. Os order bumps são criados depois de todos os produtos.

### Resultado

Problemas pontuais não interrompem a importação e entram em `resultado.avisos`. Exemplos: arquivo citado que não está no zip, ou oferta inválida.

Um erro geral marca a importação como `failed`. Exemplos: zip inválido ou manifesto sem `nome`/`preco`. Nesse caso, `resultado.produtos_criados_antes_da_falha` lista o que já foi criado. Não há desfazer automático.

## Exemplo (API da Anthropic com conector MCP)

```json
"mcp_servers": [
  {
    "type": "url",
    "url": "https://brasapay.com.br/mcp",
    "name": "brasapay",
    "authorization_token": "<mcp_pk_…>"
  }
]
```
