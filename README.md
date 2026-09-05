# Kit participativo de conectores SISC

Este diretorio contem somente o kit base para programadores criarem conectores SISC, validarem localmente e enviarem o pacote aprovado. Ele nao inclui conectores prontos, tokens locais, pacotes `dist/` nem segredos reais.

## Arquivos principais

- `manual-conector.html`: manual em HTML para preencher/criar os arquivos do conector.
- `validar-conector`: executavel de validacao local.
- `validar-conector.php`: motor da validacao.
- `excluir-conector-kitprog.c`: fonte do utilitario que remove do kit os arquivos de um conector indicado.
- `excluir-conector-kitprog`: utilitario compilado para exclusao limpa de conectores gerados.
- `conectores/`: raiz onde cada novo conector deve criar `conectores/conector-nome/`.
- `conectores/conector-nome/testes/mensagem-exemplo.json`: caminho esperado para a mensagem SISC de teste de cada conector criado.
- `siscconectores/web-api/`: raiz dos catalogos modulares criados por conector.
- `siscconectores/sisc-api-cliente.php`: biblioteca auxiliar genérica para exemplos consumidores.
- `siscconectores/cabsisc.h`: configuração base genérica para exemplos consumidores; troque `conector-nome` pelo conector real.
- Página de upload comunitária: `https://costarrear.com/gitconectores/upload.php`.

## Handler pode ser em qualquer linguagem

O handler **nao precisa ser PHP**, mas o caminho declarado como handler **precisa ser executavel diretamente**. Ele pode ser PHP, C compilado, Python, Node.js, Bash, Go, Rust ou outra tecnologia, desde que o servidor SISC tenha o runtime/interpretador/bibliotecas necessarios.

Para scripts, use shebang na primeira linha, por exemplo `#!/usr/bin/env php`, `#!/usr/bin/env python3` ou `#!/usr/bin/env node`, e aplique `chmod +x`. Para C/Go/Rust, aponte o manifesto para o binario compilado executavel.

O SISC usa o caminho declarado no manifesto:

```json
"controlador": {
  "executavel": "./escuta/runtime-conector",
  "metodoLeitura": "ler-mensagem",
  "metodoEnvio": "POST api.php",
  "regraEnvio": "somente-via-api-sem-publicacao-direta-no-espaco",
  "handlerLerMensagem": "./conectores/conector-nome/handlers/conector-nome"
}
```

Regras obrigatorias para qualquer linguagem:

- o arquivo deve ficar em `conectores/<nome>/handlers/`;
- o caminho declarado deve ser relativo seguro, sem `/` inicial, `..`, componente `.`, `//` ou barra invertida;
- deve estar executavel com `chmod +x`;
- scripts devem ter shebang na primeira linha (`#!/usr/bin/env ...`) para funcionar por execucao direta;
- deve receber o caminho da mensagem JSON como primeiro argumento;
- deve ler `_protocolo` e `payload.dados` do JSON recebido;
- deve retornar codigo `0` em sucesso e diferente de `0` em erro;
- nao pode escrever diretamente em `espaco/`;
- se precisar responder, deve usar POST HTTP para `api.php`;
- nao pode incluir segredos reais no pacote.

Exemplos validos de handler:

```text
./conectores/conector-email/handlers/conector-email.php
./conectores/conector-github/handlers/conector-github.py
./conectores/conector-node/handlers/conector-node.js
./conectores/conector-pagamentos/handlers/conector-pagamentos   # binario C/Go/Rust
./conectores/conector-shell/handlers/conector-shell.sh
```

## Front-end e opcional

Conectores SISC **nao sao obrigados a ter front-end**. Um conector pode ser totalmente backend, recebendo mensagens JSON pela escuta e respondendo com dados JSON via POST HTTP para `api.php`.

Quando nao houver front-end:

- o catalogo continua declarando as mensagens aceitas;
- `front-api` pode ficar ausente ou com `ativo:false`;
- o handler le entrada em `payload.dados`;
- a resposta, quando existir, deve ser JSON em `dados` publicado via `api.php`;
- preservar sempre `processoId` e `respostaA`.

Exemplo de resposta sem front-end:

```json
{
  "idmensagem": "conector-exemplo.resultado",
  "origem": "conector__conector-exemplo",
  "processoId": "processo-recebido",
  "respostaA": "mensagemId-recebida",
  "dados": {
    "sucesso": true,
    "resultado": "dados em JSON para o consumidor"
  }
}
```

Quando houver front-end, ele deve usar a camada segura `front-api.php`/`api.php`; o navegador nunca deve chamar handler diretamente, nem acessar `espaco/`, `siscconectores/secretos/` ou arquivos internos.

### Como fazer quando houver front-end

1. Declare a mensagem normal do conector em `siscconectores/web-api/catalogo-conector-nome.json`.
2. Dentro da mensagem, adicione `front-api` inicialmente com `ativo:false`.
3. Declare `entrada` com tipos, obrigatoriedade e limites.
4. Declare `dadosSisc`, que transforma campos do formulario em `payload.dados`.
5. Declare `resposta.atualizar` exatamente com o nome do conector. Exemplo: conector `conector-email` atualiza somente o objeto visual `id="conector-email"`/`name="conector-email"`.
6. Depois da revisao de seguranca, ative `front-api.ativo=true` e regenere o catalogo agregado no sistema instalado.
7. A tela deve chamar somente `conexao-externo/front-api.php` e atualizar apenas o objeto visual indicado pelo nome do conector.

Exemplo de `front-api` no catalogo:

```json
"front-api": [
  {
    "ativo": false,
    "acao": "conector-email.enviar.form",
    "descricao": "Formulario seguro para envio de email.",
    "metodo": "POST",
    "csrf": true,
    "autenticacao": "sessao-ou-token",
    "modo": "executar",
    "entrada": {
      "email": {"tipo": "email", "obrigatorio": true},
      "assunto": {"tipo": "string", "obrigatorio": false, "max": 200},
      "mensagem": {"tipo": "texto", "obrigatorio": true, "max": 10000}
    },
    "dadosSisc": {
      "acao": "enviar",
      "email": "$entrada.email",
      "assunto": "$entrada.assunto",
      "mensagem": "$entrada.mensagem"
    },
    "resposta": {"tipo": "json", "atualizar": "conector-email"}
  }
]
```

Exemplo de chamada JavaScript:

```js
const csrfResp = await fetch('/conexao-externo/front-api.php?csrf=1');
const csrfJson = await csrfResp.json();

const resp = await fetch('/conexao-externo/front-api.php', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': csrfJson.csrf
  },
  body: JSON.stringify({
    acao: 'conector-email.enviar.form',
    dados: {
      email: 'destinatario@exemplo.com',
      assunto: 'Assunto',
      mensagem: 'Texto da mensagem'
    }
  })
});
const json = await resp.json();
const alvo = document.getElementById(json.objetoVisual || 'conector-email');
if (alvo) alvo.innerHTML = json.html || JSON.stringify(json, null, 2);
```

Se o pacote trouxer exemplos de tela ou assets, coloque-os em:

```text
conectores/conector-nome/frontend/
```

E declare-os em `dependencias.arquivos` com `papel: "frontend"`. A publicacao desses assets em area web final deve ser decidida pelo operador. Mesmo nesses casos, a tela continua chamando apenas `front-api.php`.

Checklist de seguranca para front-end:

- usar `csrf:true` para chamadas de navegador;
- validar todos os campos em `front-api.entrada`;
- manter `front-api.resposta.atualizar` igual ao nome do conector e usar o mesmo valor como `id`/`name` do objeto visual atualizado;
- nao colocar tokens ou segredos em HTML/JS/CSS;
- nao chamar handler diretamente;
- nao acessar `espaco/`, `siscconectores/secretos/` ou arquivos internos;
- documentar as acoes de front-end no manual HTML do conector.

## Manual HTML obrigatorio do conector

Todo conector deve fornecer um manual HTML proprio para os programadores que irao consumir suas mensagens depois da instalacao.

Caminho recomendado:

```text
conectores/conector-nome/manual-conector-nome.html
```

O manifesto deve declarar:

```json
"manualUsuario": "conectores/conector-nome/manual-conector-nome.html"
```

E tambem deve listar o arquivo em `dependencias.arquivos`:

```json
{
  "papel": "manual-usuario",
  "origem": "conectores/conector-nome/manual-conector-nome.html",
  "destino": "conectores/conector-nome/manual-conector-nome.html",
  "obrigatorio": true
}
```

Qualidade esperada no HTML:

- escreva como programador do conector para outro programador que vai consumir a mensagem do catalogo;
- nao cite bastidores do servidor, scripts operacionais, comandos internos de aprovacao ou caminhos administrativos;
- identifique conector, destino SISC, catalogo modular, `idmensagem` e formato de dados;
- explique objetivo do conector em linguagem de consumo, nao de instalacao;
- inclua tabela de operacoes com operacao, `idmensagem`, campos principais e descricao;
- inclua tabela de `payload.dados` com campo, tipo, obrigatoriedade e descricao;
- inclua exemplos completos: payload simples, mensagem SISC e saida esperada;
- documente credenciais necessarias sem valores reais, inclusive quando nenhuma credencial for exigida;
- inclua tabela de erros comuns com causa provavel e acao recomendada;
- explique limites, timeouts, precisao, rate limit ou efeitos externos;
- explique seguranca de uso, dados sensiveis e idempotencia;
- inclua boas praticas para consumidores;
- documente o programa de exemplo fornecido no pacote e o que ele demonstra.

Use como referência de qualidade os exemplos embutidos em `manual-conector.html`. Antes de empacotar, rode `./validar-conector`; ele reprova manual raso, generico, sem exemplos/tabelas ou que cite detalhes internos do servidor em vez de documentar o conector.

## Programa de exemplo obrigatorio

O programador deve entregar um programa de exemplo que use os recursos do seu conector. O caminho esperado é:

```text
siscconectores/conector-nome-uso.php
siscconectores/conector-nome-cliente.php
```

Arquivos auxiliares de cliente devem ficar junto dos exemplos:

```text
siscconectores/cabsisc.h
siscconectores/sisc-api-cliente.php
```

Tokens locais de consumo ficam em `token-sisc/<login>.txt`, fora do pacote enviado. Como um mesmo arquivo pode guardar tokens de vários conectores, cada linha de token deve usar o formato:

```text
conector-nome~TOKEN_DO_CONECTOR
```

No `cabsisc.h`, informe o conector no construtor para selecionar o token correto:

```php
$sisc = new sisc('siscore', 'meu-login', 'conector-nome');
```

O exemplo deve:

- ser PHP válido;
- montar uma mensagem ou payload realista usando o `idmensagem` do catalogo;
- demonstrar `payload.dados` com os campos reais do conector;
- explicar pelo próprio código o que a chamada produz;
- ter modo `--self-test` que imprime JSON válido com `sucesso:true`, `conector`, `idmensagem`, `payload.dados` e `saidaEsperada`.

O `./validar-conector` executa `php -l` e `php siscconectores/conector-nome-uso.php --self-test`. Se o programa não produzir o JSON prometido ou usar `idmensagem` fora do catalogo, o pacote é reprovado.

## Credenciais, senhas e tokens

Conectores podem precisar de senha ou token para acessar servicos externos, como Gmail, SMTP, IMAP, APIs REST etc. Isso e permitido, mas **segredos reais nunca devem ir para o GitHub nem para o pacote enviado**.

Padrao obrigatorio:

- envie somente `siscconectores/secretos/conector-nome.sample.json` com estrutura de exemplo;
- nao envie `siscconectores/secretos/conector-nome.json` real;
- o operador cria o arquivo real apenas no servidor SISC;
- o arquivo real deve ter permissao restrita, por exemplo `chmod 600 siscconectores/secretos/conector-email.json`;
- o manifesto deve apontar a referencia em `servicoExterno.credenciais.referencia`;
- o handler deve falhar claramente se o segredo estiver ausente ou incompleto;
- o handler nunca deve imprimir senha, token ou Authorization header em logs.

Exemplo de manifesto:

```json
"servicoExterno": {
  "nome": "gmail",
  "credenciais": {
    "referencia": "siscconectores/secretos/conector-email.json",
    "tipo": "arquivo-json-local-restrito",
    "permissaoRecomendada": "600"
  }
}
```

Exemplo que pode ir ao GitHub:

```json
{
  "ativo": false,
  "smtp": {
    "ativo": false,
    "host": "smtp.gmail.com",
    "porta": 587,
    "criptografia": "tls",
    "usuario": "usuario@gmail.com",
    "senha": "preencher-no-servidor-real"
  }
}
```

No caso do Gmail, normalmente o operador nao deve usar a senha comum da conta. Use App Password ou OAuth2, conforme a implementacao do conector e as regras atuais do Google.

O validador recusa arquivos reais em `siscconectores/secretos/*.json` e permite somente `siscconectores/secretos/*.sample.json`.

## Validacao local

```bash
cd kitprog
./validar-conector
```

## Exclusao limpa de um conector gerado

```bash
gcc -O2 -Wall -Wextra -o excluir-conector-kitprog excluir-conector-kitprog.c
./excluir-conector-kitprog --dry-run conector-nome
./excluir-conector-kitprog conector-nome
```

O utilitario remove diretorio do conector, catalogo modular, exemplos em `siscconectores/`, segredo sample, pacote em `dist` e referencias textuais restantes fora de `.git`.

Para testar o handler do modelo:

```bash
./conectores/conector-nome/handlers/executavel-do-handler conectores/conector-nome/testes/mensagem-exemplo.json
```

Teste de aceitacao do handler, reproduzindo como o SISC chamara o executavel:

```bash
chmod +x conectores/conector-nome/handlers/executavel-do-handler
./conectores/conector-nome/handlers/executavel-do-handler conectores/conector-nome/testes/mensagem-exemplo.json
```

Chamadas como `php handler.php`, `python3 handler.py` ou `node handler.js` servem para depuracao, mas a submissao final deve funcionar por execucao direta do caminho declarado em `handlerLerMensagem`.

## Declaracao para sandbox automatico

Para o servidor poder executar o pacote em `testesis` antes de integrar ao SISC real, o manifesto deve declarar explicitamente que a mensagem de teste nao causa efeito real:

```json
"testeSandbox": {
  "permitido": true,
  "semEfeitoReal": true,
  "mensagem": "conectores/conector-nome/testes/mensagem-exemplo.json",
  "descricao": "explique por que este teste e seguro/sandbox"
}
```

Liste tambem essa mensagem em `dependencias.arquivos` com `papel: "teste-sandbox"`, usando origem e destino `conectores/conector-nome/testes/mensagem-exemplo.json`.

Sem essa declaracao, o validador local agora reprova o pacote, porque o servidor nao emitira `selo-sandbox` e o `siscore` real recusara a instalacao sem os dois selos.

O servidor tambem faz preflight antes de emitir `selo-sandbox`: se `SISC_SANDBOX_DESATIVADO` estiver definido, ou se `testesis/escuta/sandbox-handler`/`runtime-conector` nao estiverem com o sandbox novo, o teste e bloqueado e nenhum selo e emitido.

## Empacotamento sugerido apos aprovacao

```bash
CONECTOR=conector-nome
VERSAO=1.0.0
mkdir -p "conectores/$CONECTOR/dist"
tar --exclude="./conectores/$CONECTOR/dist" --exclude='./dist' --exclude='.git' \
  --exclude='./token-sisc' --exclude='./siscconectores/token-sisc' \
  -czf "conectores/$CONECTOR/dist/$CONECTOR-$VERSAO.tar.gz" .
```

Envie somente pacotes aprovados pelo `./validar-conector`. O validador local rejeita links simbolicos, caminhos inseguros, handler fora de `conectores/<nome>/handlers/`, handler sem execucao direta, mensagem de teste fora de `conectores/<nome>/testes/mensagem-exemplo.json`, dependencias instalaveis fora de `conectores/<nome>/`, `siscconectores/` ou destino final `web-api/`, segredos reais e `testeSandbox` ausente/invalido.
