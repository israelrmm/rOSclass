<?php
/*****************************
 * RouterOS PHP API class (Versión optimized JAN 2026)
 * For php 7.0+
 * Based on Denis Basta works, By Israel Marrero
 ***********************************************/

class RouterosAPI
{
    // Propiedades con tipos definidos (PHP 7.4+)
    public bool $debug      = false;
    public bool $connected  = false;
    public int $port        = 8728;
    public bool $ssl        = false;
    public int $timeout     = 225;
    public int $attempts    = 3;
    public int $delay       = 2;

    protected $socket;
    public $error_no;
    public $error_str;

    /**
     * Imprime mensajes de depuración en consola.
     */
    protected function debug(string $text): void
    {
        if ($this->debug) {
            echo "[RouterOS API] " . $text . PHP_EOL;
        }
    }

    /**
     * Codifica la longitud de la cadena según el protocolo de MikroTik.
     */
    public function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        } elseif ($length < 0x4000) {
            return chr(($length >> 8) | 0x80) . chr($length & 0xFF);
        } elseif ($length < 0x200000) {
            return chr(($length >> 16) | 0xC0) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        } elseif ($length < 0x10000000) {
            return chr(($length >> 24) | 0xE0) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }
        return chr(0xF0) . chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
    }

    /**
     * Establece la conexión y realiza el login.
     */
    public function connect(string $ip, string $login, string $password): bool
    {
        for ($a = 1; $a <= $this->attempts; $a++) {
            $this->connected = false;
            $protocol = ($this->ssl ? 'ssl://' : 'tcp://');

            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ]);

            $this->socket = @stream_socket_client(
                $protocol . $ip . ':' . $this->port,
                $this->error_no,
                $this->error_str,
                $this->timeout,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if (is_resource($this->socket)) {
                stream_set_timeout($this->socket, $this->timeout);

                if ($this->loginProcess($login, $password)) {
                    $this->connected = true;
                    $this->debug("Connected to $ip");
                    return true;
                }

                fclose($this->socket);
            }

            if ($a < $this->attempts) {
                sleep($this->delay);
            }
        }

        $this->debug("Failed to connect to $ip");
        return false;
    }

    /**
     * Maneja el proceso de autenticación (compatible con v6.43+ y versiones antiguas).
     */
    private function loginProcess(string $login, string $password): bool
    {
        $this->write('/login', false);
        $this->write('=name=' . $login, false);
        $this->write('=password=' . $password);

        $response = $this->read(false);

        if (isset($response[0]) && $response[0] == '!done') {
            if (!isset($response[1])) {
                return true;
            } else {
                if (preg_match('/ret=([0-9a-f]{32})/', $response[1], $matches)) {
                    $this->write('/login', false);
                    $this->write('=name=' . $login, false);
                    $this->write('=response=00' . md5(chr(0) . $password . pack('H*', $matches[1])));
                    $response = $this->read(false);
                    return (isset($response[0]) && $response[0] == '!done');
                }
            }
        }
        return false;
    }

    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->connected = false;
    }

    /**
     * Escribe palabras en el socket siguiendo el protocolo de longitud + palabra.
     */
    public function write(string $command, bool $terminal = true): bool
    {
        if (!is_resource($this->socket)) return false;

        $command = trim($command);
        fwrite($this->socket, $this->encodeLength(strlen($command)) . $command);
        $this->debug("<<< $command");

        if ($terminal) {
            fwrite($this->socket, chr(0));
        }

        return true;
    }

    /**
     * Lee la respuesta del RouterOS.
     */
    public function read(bool $parse = true)
    {
        $responses = [];
        while (is_resource($this->socket)) {
            $byteStr = fread($this->socket, 1);
            if ($byteStr === false || $byteStr === "") break;

            $byte = ord($byteStr);
            $length = 0;

            if ($byte & 128) {
                if (($byte & 192) == 128) $length = (($byte & 63) << 8) + ord(fread($this->socket, 1));
                elseif (($byte & 224) == 192) $length = (($byte & 31) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
                elseif (($byte & 240) == 224) $length = (($byte & 15) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
                else $length = (ord(fread($this->socket, 1)) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
            } else {
                $length = $byte;
            }

            $chunk = "";
            while (strlen($chunk) < $length) {
                $chunk .= fread($this->socket, $length - strlen($chunk));
            }

            $responses[] = $chunk;
            $this->debug(">>> $chunk");

            if ($chunk == "!done") break;

            $meta = stream_get_meta_data($this->socket);
            if ($meta['timed_out']) break;
        }

        return $parse ? $this->parseResponse($responses) : $responses;
    }

    /**
     * Convierte la respuesta plana en un array asociativo.
     */
    public function parseResponse(array $response): array
    {
        $parsed = [];
        $current = null;

        foreach ($response as $line) {
            if ($line === '!re') {
                $parsed[] = [];
                $current = &$parsed[count($parsed) - 1];
            } elseif ($line === '!trap' || $line === '!fatal') {
                $parsed[$line][] = [];
                $current = &$parsed[$line][count($parsed[$line]) - 1];
            } elseif (strpos($line, '=') === 0) {
                $parts = explode('=', substr($line, 1), 2);
                $current[$parts[0]] = $parts[1] ?? '';
            }
        }
        return $parsed;
    }

    /**
     * Ejecuta comandos enviando un string completo (ej: "/ip/address/print").
     */
    public function execmd(string $command): array
    {
        if (!$command) return [];

        $data = explode(" ", trim($command));
        $count = count($data);

        foreach ($data as $i => $com) {
            $last = ($i === $count - 1);
            $prefix = "";
            if ($i > 0) {
                $first_char = $com[0];
                if ($first_char !== "~" && $first_char !== "?") {
                    $prefix = "=";
                }
            }
            $this->write($prefix . $com, $last);
        }
        return $this->read();
    }

    /**
     * Método preferido para enviar comandos con arrays de parámetros.
     */
    public function comm(string $com, array $arr = []): array
    {
        $this->write($com, empty($arr));
        $i = 0;
        $count = count($arr);
        foreach ($arr as $k => $v) {
            $prefix = in_array($k[0], ['?', '~']) ? '' : '=';
            $this->write($prefix . $k . '=' . $v, ++$i === $count);
        }
        return $this->read();
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}

