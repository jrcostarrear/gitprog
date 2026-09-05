<?php
declare(strict_types=1);

/*
 * Cliente PHP simples para o SISC.
 * Para uso do cliente existem apenas dois metodos: mensagens() e enviar().
 * No sistema SISC, tokens de cliente devem ficar somente em token-sisc/<login>.txt.
 */
final class sisc
{
    private const URL_BASE_PADRAO = 'https://costarrear.com/sisc';

    private string $urlApi;
    private string $token;
    private string $origem;
    private string $sistema;
    private ?string $conector;
    private int $timeout = 25;

    public function __construct(string $sistema, string $login, ?string $conector = null)
    {
        $this->sistema = self::nomeSistema($sistema);
        $this->conector = $conector !== null && $conector !== '' ? self::nomeConector($conector) : null;

        $config = self::lerLogin($login, $this->conector);

        $this->urlApi = $config['url'] ?? (self::URL_BASE_PADRAO . '/' . $this->sistema . '/conexao-externo/api.php');
        $this->token = $config['token'] ?? '';
        $this->origem = $config['origem'] ?? ('sistema__' . self::idSeguro($config['nome'] ?? 'cliente'));

        if (!filter_var($this->urlApi, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('URL da API SISC invalida.');
        }
        if (strlen($this->token) < 16) {
            throw new InvalidArgumentException('Token SISC invalido ou ausente.');
        }
        if (!self::entidadeValida($this->origem)) {
            throw new InvalidArgumentException('Origem SISC invalida.');
        }
    }

    public function mensagens(): array
    {
        $resposta = $this->getJson($this->urlComQuery(['mensagens' => '1']));
        $mensagens = $resposta['mensagens'] ?? [];
        return is_array($mensagens) ? $mensagens : [];
    }

    public function enviar(string $idmensagem, mixed $dados = [], string $modo = ''): array
    {
        if (!self::idValido($idmensagem)) {
            throw new InvalidArgumentException('idmensagem invalido.');
        }

        $payload = [
            'idmensagem' => $idmensagem,
            'dados' => $dados,
            'origem' => $this->origem,
        ];

        if ($modo !== '') {
            if ($modo === 'dry-run') {
                $payload['dryRun'] = true;
            } elseif ($modo === 'executar') {
                $payload['executar'] = true;
                $payload['idempotencia'] = 'cliente-' . bin2hex(random_bytes(16));
            } else {
                throw new InvalidArgumentException('Modo invalido. Use dry-run, executar ou deixe vazio.');
            }
        }

        return $this->postJson($this->urlApi, $payload);
    }

    private static function lerLogin(string $login, ?string $conector): array
    {
        $nome = self::nomeLogin($login);
        $arquivo = self::caminhoLogin($nome);

        if ($arquivo === null) {
            throw new RuntimeException('Arquivo de login SISC nao encontrado em token-sisc/' . $nome . '.txt.');
        }

        $conteudo = file_get_contents($arquivo, false, null, 0, 65536);
        if (!is_string($conteudo)) {
            throw new RuntimeException('Nao foi possivel ler o arquivo de login SISC.');
        }

        $config = ['nome' => $nome];
        $tokensPorConector = [];
        $tokenUnico = null;
        foreach (preg_split('/\R/', $conteudo) ?: [] as $linha) {
            $linha = trim($linha);
            if ($linha === '' || $linha[0] === '#') {
                continue;
            }

            $posTokenConector = strpos($linha, '~');
            if ($posTokenConector !== false) {
                $nomeToken = self::nomeConector(trim(substr($linha, 0, $posTokenConector)));
                $valorToken = trim(substr($linha, $posTokenConector + 1));
                if ($valorToken !== '') {
                    $tokensPorConector[$nomeToken] = $valorToken;
                }
                continue;
            }

            $chave = '';
            $valor = $linha;
            foreach (['=', ':'] as $sep) {
                $pos = strpos($linha, $sep);
                if ($pos !== false) {
                    $chave = strtolower(trim(substr($linha, 0, $pos)));
                    $valor = trim(substr($linha, $pos + 1));
                    break;
                }
            }

            if ($chave === 'url' || $chave === 'api' || $chave === 'api_url' || $chave === 'sisc_api_url') {
                $config['url'] = $valor;
            } elseif ($chave === 'origem' || $chave === 'sisc_api_origem') {
                $config['origem'] = $valor;
            } elseif ($chave === 'token' || $chave === 'sisc_api_token' || $chave === $nome || $chave === '') {
                $tokenUnico = $valor;
            }
        }

        if ($conector !== null && isset($tokensPorConector[$conector])) {
            $config['token'] = $tokensPorConector[$conector];
        } elseif ($conector !== null && count($tokensPorConector) > 0) {
            throw new RuntimeException('Token SISC ausente para ' . $conector . ' em token-sisc/' . $nome . '.txt. Use o formato conector-nome~TOKEN.');
        } elseif (count($tokensPorConector) === 1) {
            $config['token'] = reset($tokensPorConector);
        } elseif ($tokenUnico !== null) {
            $config['token'] = $tokenUnico;
        } elseif (count($tokensPorConector) > 1) {
            throw new RuntimeException('Arquivo token-sisc/' . $nome . '.txt possui varios tokens; informe o conector no construtor.');
        }

        if (empty($config['token'])) {
            throw new RuntimeException('Token SISC ausente no arquivo de login.');
        }

        return $config;
    }

    private static function caminhoLogin(string $nome): ?string
    {
        $cwd = getcwd();
        $candidatos = [
            __DIR__ . '/token-sisc/' . $nome . '.txt',
            __DIR__ . '/../token-sisc/' . $nome . '.txt',
        ];
        if (is_string($cwd) && $cwd !== '') {
            $candidatos[] = $cwd . '/token-sisc/' . $nome . '.txt';
            $candidatos[] = $cwd . '/../token-sisc/' . $nome . '.txt';
        }

        foreach (array_unique($candidatos) as $arquivo) {
            if (is_file($arquivo) && is_readable($arquivo)) {
                return $arquivo;
            }
        }
        return null;
    }

    private static function nomeLogin(string $login): string
    {
        $login = trim($login);
        $login = preg_replace('/\.txt$/i', '', $login) ?? $login;
        if ($login === '' || preg_match('/^[A-Za-z0-9._-]{1,128}$/', $login) !== 1) {
            throw new InvalidArgumentException('Nome de login SISC invalido.');
        }
        return $login;
    }

    private static function nomeSistema(string $sistema): string
    {
        $sistema = trim($sistema);
        $sistema = preg_replace('/\.txt$/i', '', $sistema) ?? $sistema;
        if ($sistema === '' || preg_match('/^[A-Za-z0-9._-]{1,128}$/', $sistema) !== 1) {
            throw new InvalidArgumentException('Nome de sistema SISC invalido.');
        }
        return $sistema;
    }

    private static function nomeConector(string $conector): string
    {
        $conector = trim($conector);
        if ($conector === '' || preg_match('/^conector-[a-z0-9][a-z0-9-]{0,150}[a-z0-9]$/', $conector) !== 1) {
            throw new InvalidArgumentException('Nome de conector SISC invalido.');
        }
        return $conector;
    }

    private static function idSeguro(string $valor): string
    {
        $id = preg_replace('/[^A-Za-z0-9._-]/', '-', $valor) ?? 'cliente';
        $id = trim($id, '.-_');
        if ($id === '' || preg_match('/^[A-Za-z0-9]/', $id) !== 1) {
            $id = 'cliente';
        }
        return substr($id, 0, 127);
    }

    private static function idValido(string $valor): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $valor) === 1;
    }

    private static function entidadeValida(string $valor): bool
    {
        if (strlen($valor) > 160) {
            return false;
        }
        foreach (['agente__' => 8, 'conector__' => 10, 'sistema__' => 9] as $prefixo => $offset) {
            if (strncmp($valor, $prefixo, $offset) === 0) {
                return self::idValido(substr($valor, $offset));
            }
        }
        return false;
    }

    private function urlComQuery(array $params): string
    {
        return $this->urlApi . (str_contains($this->urlApi, '?') ? '&' : '?') . http_build_query($params);
    }

    private function getJson(string $url): array
    {
        return $this->httpJson('GET', $url, null);
    }

    private function postJson(string $url, array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Nao foi possivel montar o JSON da requisicao SISC.');
        }
        return $this->httpJson('POST', $url, $json);
    }

    private function httpJson(string $metodo, string $url, ?string $json): array
    {
        if (function_exists('curl_init')) {
            return $this->httpJsonCurl($metodo, $url, $json);
        }
        return $this->httpJsonStream($metodo, $url, $json);
    }

    private function httpJsonCurl(string $metodo, string $url, ?string $json): array
    {
        $ch = curl_init($url);
        if (!$ch) {
            throw new RuntimeException('Nao foi possivel iniciar a conexao com o SISC.');
        }

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
        ];

        $opcoes = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($metodo === 'POST') {
            $headers[] = 'Content-Type: application/json';
            $opcoes[CURLOPT_POST] = true;
            $opcoes[CURLOPT_POSTFIELDS] = $json ?? '{}';
            $opcoes[CURLOPT_HTTPHEADER] = $headers;
        }

        curl_setopt_array($ch, $opcoes);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erro = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Falha ao chamar o SISC: ' . $erro);
        }

        return $this->tratarResposta($status, (string)$body);
    }

    private function httpJsonStream(string $metodo, string $url, ?string $json): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
        ];

        $http = [
            'method' => $metodo,
            'timeout' => $this->timeout,
            'ignore_errors' => true,
            'header' => implode("\r\n", $headers),
        ];

        if ($metodo === 'POST') {
            $headers[] = 'Content-Type: application/json';
            $http['header'] = implode("\r\n", $headers);
            $http['content'] = $json ?? '{}';
        }

        $body = @file_get_contents($url, false, stream_context_create(['http' => $http]));
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $m) === 1) {
                $status = (int)$m[1];
                break;
            }
        }

        if ($body === false) {
            throw new RuntimeException('Falha ao chamar o SISC.');
        }

        return $this->tratarResposta($status, (string)$body);
    }

    private function tratarResposta(int $status, string $body): array
    {
        $resposta = json_decode($body, true);
        if (!is_array($resposta)) {
            throw new RuntimeException('Resposta invalida recebida do SISC.');
        }

        if ($status < 200 || $status >= 300) {
            $erro = $resposta['erro'] ?? $resposta['mensagem'] ?? 'Erro retornado pelo SISC.';
            throw new RuntimeException('SISC HTTP ' . $status . ': ' . (is_string($erro) ? $erro : 'Erro retornado pelo SISC.'));
        }

        return $resposta;
    }
}
