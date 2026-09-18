<?php
/*****************************
 * RouterOS PHP API class ( sep 2026)
 * For php 7.0+
 * Based in work of https://github.com/BenMenking/routeros-api
 * by Israel Marrero
 ***********************************************/

class RouterosAPI
{
    // Propiedades con tipos definidos (PHP 7.4+)
    public bool $debug      = false;
    public bool $connected  = false;
    public int  $port       = 8728;
    public bool $ssl        = false;
    public int  $timeout    = 225;
    public int  $attempts   = 3;
    public int  $delay      = 2;

    /** Tamaño del bloque de lectura del socket (bytes). */
    public int $readChunkSize = 8192;

    /** @var resource|null */
    protected $socket;

    /** Buffer interno para evitar fread() byte a byte. */
    protected string $readBuffer = '';

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

    /* ---------------------------------------------------------------------
     *  Buffer de lectura — la gran optimización
     * ------------------------------------------------------------------- */

    /**
     * Devuelve exactamente $n bytes (o menos si el socket cierra/timeout).
     * Lee del socket en bloques grandes y mantiene un buffer interno.
     */
    protected function readBytes(int $n): string
    {
        if ($n <= 0) return '';

        $buffered = strlen($this->readBuffer);
        while ($buffered < $n) {
            $need  = $n - $buffered;
            $chunk = fread($this->socket, max($this->readChunkSize, $need));
            if ($chunk === false || $chunk === '') {
                break; // timeout o desconexión
            }
            $this->readBuffer .= $chunk;
            $buffered += strlen($chunk);
        }

        if ($buffered === 0) return '';

        if ($buffered <= $n) {
            $data = $this->readBuffer;
            $this->readBuffer = '';
            return $data;
        }

        $data = substr($this->readBuffer, 0, $n);
        $this->readBuffer = substr($this->readBuffer, $n);
        return $data;
    }

    /* ---------------------------------------------------------------------
     *  Conexión
     * ------------------------------------------------------------------- */
    public function connect(string $ip, string $login, string $password): bool
    {
        for ($a = 1; $a <= $this->attempts; $a++) {
            $this->connected  = false;
            $this->readBuffer = '';

            $protocol = ($this->ssl ? 'ssl://' : 'tcp://');

            $context = stream_context_create([
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
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

                // Desactivar Nagle (TCP_NODELAY): elimina el retardo de ~40 ms
                // por comando. Es opcional: si la extensión sockets no existe
                // simplemente se ignora.
                if (function_exists('socket_import_stream')) {
                    $sock = @socket_import_stream($this->socket);
                    if ($sock !== false && defined('TCP_NODELAY')) {
                        @socket_set_option($sock, SOL_TCP, TCP_NODELAY, 1);
                    }
                }

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

        if (($response[0] ?? null) === '!done') {
            if (!isset($response[1])) {
                return true;
            }
            if (preg_match('/ret=([0-9a-f]{32})/', $response[1], $matches)) {
                $this->write('/login', false);
                $this->write('=name=' . $login, false);
                $this->write('=response=00' . md5(chr(0) . $password . pack('H*', $matches[1])));
                $response = $this->read(false);
                return (($response[0] ?? null) === '!done');
            }
        }
        return false;
    }

    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->connected  = false;
        $this->readBuffer = '';
    }

    /* ---------------------------------------------------------------------
     *  Escritura
     * ------------------------------------------------------------------- */

    /**
     * Escribe longitud + palabra (+ terminador si $terminal).
     * Se hace todo en un solo fwrite → 1 syscall en lugar de 2.
     */
    public function write(string $command, bool $terminal = true): bool
    {
        if (!is_resource($this->socket)) return false;

        $command = trim($command);
        $data = $this->encodeLength(strlen($command)) . $command;
        if ($terminal) {
            $data .= chr(0);
        }

        fwrite($this->socket, $data);
        $this->debug("<<< $command");
        return true;
    }

    /* ---------------------------------------------------------------------
     *  Lectura
     * ------------------------------------------------------------------- */

    /**
     * Lee la respuesta del RouterOS.
     */
    public function read(bool $parse = true)
    {
        $responses = [];

        while (is_resource($this->socket)) {
            $byteStr = $this->readBytes(1);
            if ($byteStr === '') break;

            $byte = ord($byteStr);

            // Decodificar longitud (protocolo MikroTik)
            if ($byte & 128) {
                if (($byte & 192) === 128) {
                    $length = (($byte & 63) << 8) | ord($this->readBytes(1));
                } elseif (($byte & 224) === 192) {
                    $length = (($byte & 31) << 16)
                            | (ord($this->readBytes(1)) << 8)
                            |  ord($this->readBytes(1));
                } elseif (($byte & 240) === 224) {
                    $length = (($byte & 15) << 24)
                            | (ord($this->readBytes(1)) << 16)
                            | (ord($this->readBytes(1)) << 8)
                            |  ord($this->readBytes(1));
                } else {
                    $length = (ord($this->readBytes(1)) << 24)
                            | (ord($this->readBytes(1)) << 16)
                            | (ord($this->readBytes(1)) << 8)
                            |  ord($this->readBytes(1));
                }
            } else {
                $length = $byte;
            }

            $chunk = $length > 0 ? $this->readBytes($length) : '';
            $responses[] = $chunk;

            if ($this->debug) {
                $this->debug(">>> $chunk");
            }

            // Fin de respuesta
            if ($chunk === '!done') break;

            // Evitar bloqueos prolongados
            $meta = stream_get_meta_data($this->socket);
            if (!empty($meta['timed_out'])) break;
        }

        return $parse ? $this->parseResponse($responses) : $responses;
    }

    /**
     * Convierte la respuesta plana en un array asociativo.
     */
    public function parseResponse(array $response): array
    {
        $parsed  = [];
        $current = null;

        foreach ($response as $line) {
            $first = $line !== '' ? $line[0] : '';

            if ($line === '!re') {
                $parsed[] = [];
                $current  = &$parsed[count($parsed) - 1];
            } elseif ($line === '!trap' || $line === '!fatal') {
                if (!isset($parsed[$line])) $parsed[$line] = [];
                $parsed[$line][] = [];
                $current = &$parsed[$line][count($parsed[$line]) - 1];
            } elseif ($first === '=' && $current !== null) {
                $eq = strpos($line, '=', 1);
                if ($eq === false) {
                    $current[substr($line, 1)] = '';
                } else {
                    $current[substr($line, 1, $eq - 1)] = substr($line, $eq + 1);
                }
            }
        }
        return $parsed;
    }

    /**
     * Ejecuta comandos enviando un string completo (ej: "/ip/address/print").
     */
    public function execmd(string $command): array
    {
        if ($command === '') return [];

        $data  = explode(' ', trim($command));
        $count = count($data);

        foreach ($data as $i => $com) {
            if ($com === '') continue;
            $last   = ($i === $count - 1);
            $prefix = '';
            if ($i > 0) {
                $fc = $com[0];
                if ($fc !== '~' && $fc !== '?') {
                    $prefix = '=';
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
        $i     = 0;
        $count = count($arr);
        foreach ($arr as $k => $v) {
            $first  = $k !== '' ? $k[0] : '';
            $prefix = ($first === '?' || $first === '~') ? '' : '=';
            $this->write($prefix . $k . '=' . $v, ++$i === $count);
        }
        return $this->read();
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}

