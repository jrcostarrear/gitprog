# Kit participativo de conectores SISC

Este diretorio contem um modelo completo para programadores criarem conectores SISC, validarem localmente e enviarem o pacote aprovado.

## Arquivos principais

- `manual-conector.html`: manual em HTML para preencher/criar os arquivos do conector.
- `validar-conector`: executavel de validacao local.
- `validar-conector.php`: motor da validacao.
- `conectores/conector-modelo/`: conector funcional de exemplo.
- `conectores/conector-modelo/manual-conector-modelo.html`: manual HTML do exemplo para utilizadores do conector.
- `web-api/catalogo-conector-modelo.json`: catalogo modular de mensagens do exemplo.
- `testes/mensagem-exemplo.json`: mensagem SISC de teste.
- `conectores/conector-modelo/exemplos/exemplo-uso.php`: programa PHP de exemplo que demonstra o uso do conector e possui `--self-test` validável.
- `conectores/conector-modelo/exemplos/exemplo-cliente.php`: programa consumidor de exemplo, validável com `--self-test`, usando `cabsisc.h` e a biblioteca cliente.
- `cabsisc.h`: configuracao local de sistema/login para exemplos de consumo; e o arquivo que o programador ajusta.
- `sisc-api-cliente.php`: biblioteca cliente generica do SISC; nao altere para configurar conectores.
- `token-externo/.gitignore`: mantem tokens locais fora do GitHub e fora dos pacotes.
- `enviar-conector-sisc`: envio do pacote validado para homologacao/aprovacao.
- Página de upload comunitária: `https://costarrear.com/gitconectores/upload.php`.

## Configuração de consumo e teste

Para consumir ou testar conectores pelo kit, o programador deve editar apenas a configuracao local em `cabsisc.h` e criar o arquivo de token correspondente em `token-externo/<login>.txt`.

```php
$sisc = new sisc('testesis', 'meu-login');
```

Regra importante: `sisc-api-cliente.php` e biblioteca generica. Para outro conector ou outro login, nao altere essa biblioteca; ajuste `cabsisc.h`, o arquivo `token-externo/<login>.txt` e os dados de negocio do exemplo do conector. O kit deve apontar para `testesis` durante homologacao; `siscore` e alvo de producao do servidor depois dos selos validos, nao do teste local do programador.

O arquivo de token pode conter token puro ou pares como:

```text
token=<token recebido do operador>
url=https://costarrear.com/sisc/testesis/conexao-externo/api.php
origem=sistema__cliente-exemplo
```

Nunca envie `token-externo/*.txt` ao GitHub nem dentro do pacote do conector.

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

Quando houver front-end, ele deve usar a camada segura `front-api.php`/`api.php`; o navegador nunca deve chamar handler diretamente, nem acessar `espaco/`, `secretos/` ou arquivos internos.

### Como fazer quando houver front-end

1. Declare a mensagem normal do conector em `web-api/catalogo-conector-nome.json`.
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
- nao acessar `espaco/`, `secretos/` ou arquivos internos;
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
- documente os programas de exemplo fornecidos no pacote e o que cada um demonstra.

O modelo de qualidade fica em `conectores/conector-modelo/manual-conector-modelo.html`. Antes de empacotar, rode `./validar-conector`; ele reprova manual raso, generico, sem exemplos/tabelas ou que cite detalhes internos do servidor em vez de documentar o conector.

## Programas de exemplo obrigatorios

O programador deve entregar dois programas de exemplo dentro do diretório do conector:

```text
conectores/conector-nome/exemplos/exemplo-uso.php
conectores/conector-nome/exemplos/exemplo-cliente.php
```

O `exemplo-uso.php` deve:

- ser PHP válido;
- montar uma mensagem ou payload realista usando o `idmensagem` do catalogo;
- demonstrar `payload.dados` com os campos reais do conector;
- explicar pelo próprio código o que a chamada produz;
- ter modo `--self-test` que imprime JSON válido com `sucesso:true`, `conector`, `idmensagem`, `payload.dados` e `saidaEsperada`.

O `exemplo-cliente.php` deve demonstrar consumo real pela API SISC usando a estrutura nova do kit: carregar `cabsisc.h` da raiz com `require dirname(__DIR__, 3) . '/cabsisc.h';`, usar `$sisc->enviar(...)`, ler sistema/login/token a partir de `cabsisc.h` e `token-externo/<login>.txt`, e também ter `--self-test` sem depender de token real.

O manifesto deve listar os dois exemplos em `dependencias.arquivos` com os papéis `exemplo-uso` e `exemplo-consumidor`. O `./validar-conector` executa `php -l`, `exemplo-uso.php --self-test` e `exemplo-cliente.php --self-test`. Se algum programa não produzir o JSON prometido ou usar `idmensagem` fora do catalogo, o pacote é reprovado.

## Credenciais, senhas e tokens

Conectores podem precisar de senha ou token para acessar servicos externos, como Gmail, SMTP, IMAP, APIs REST etc. Isso e permitido, mas **segredos reais nunca devem ir para o GitHub nem para o pacote enviado**.

Padrao obrigatorio:

- envie somente `secretos/conector-nome.sample.json` com estrutura de exemplo;
- nao envie `secretos/conector-nome.json` real;
- o operador cria o arquivo real apenas no servidor SISC;
- o arquivo real deve ter permissao restrita, por exemplo `chmod 600 secretos/conector-email.json`;
- o manifesto deve apontar a referencia em `servicoExterno.credenciais.referencia`;
- o handler deve falhar claramente se o segredo estiver ausente ou incompleto;
- o handler nunca deve imprimir senha, token ou Authorization header em logs.

Exemplo de manifesto:

```json
"servicoExterno": {
  "nome": "gmail",
  "credenciais": {
    "referencia": "secretos/conector-email.json",
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

O validador recusa arquivos reais em `secretos/*.json` e permite somente `secretos/*.sample.json`.

## Validacao local

```bash
cd gitprog
./validar-conector
```

Para testar o handler do modelo:

```bash
php conectores/conector-modelo/handlers/conector-modelo.php testes/mensagem-exemplo.json
```

Teste de aceitacao do handler, reproduzindo como o SISC chamara o executavel:

```bash
chmod +x conectores/conector-nome/handlers/executavel-do-handler
./conectores/conector-nome/handlers/executavel-do-handler testes/mensagem-exemplo.json
```

Chamadas como `php handler.php`, `python3 handler.py` ou `node handler.js` servem para depuracao, mas a submissao final deve funcionar por execucao direta do caminho declarado em `handlerLerMensagem`.

## Declaracao para sandbox automatico

Para o servidor poder executar o pacote em `testesis` antes de integrar ao SISC real, o manifesto deve declarar explicitamente que a mensagem de teste nao causa efeito real:

```json
"testeSandbox": {
  "permitido": true,
  "semEfeitoReal": true,
  "mensagem": "testes/mensagem-exemplo.json",
  "descricao": "explique por que este teste e seguro/sandbox"
}
```

Sem essa declaracao, o validador local agora reprova o pacote, porque o servidor nao emitira `selo-sandbox` e o `siscore` real recusara a instalacao sem os dois selos.

O servidor tambem faz preflight antes de emitir `selo-sandbox`: se `SISC_SANDBOX_DESATIVADO` estiver definido, ou se `testesis/escuta/sandbox-handler`/`runtime-conector` nao estiverem com o sandbox novo, o teste e bloqueado e nenhum selo e emitido.

## Empacotamento sugerido apos aprovacao

```bash
tar --exclude='./dist' --exclude='.git' --exclude='./token-externo/*.txt' -czf dist/conector-nome.tar.gz .
```

Envie somente pacotes aprovados pelo `./validar-conector`. O validador local rejeita links simbolicos, caminhos inseguros, handler fora de `conectores/<nome>/handlers/`, handler sem execucao direta, dependencias instalaveis fora de `conectores/<nome>/` ou `web-api/`, segredos reais e `testeSandbox` ausente/invalido.
