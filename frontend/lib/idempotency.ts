/**
 * Sorteia a chave de idempotência de uma tentativa de escrita.
 *
 * Existe por um bug que só aparece fora do `localhost`: `crypto.randomUUID()`
 * é restrito a CONTEXTO SEGURO — HTTPS, ou localhost por exceção do browser.
 * Servida por HTTP simples em qualquer outro host — um IP na rede interna, o
 * nome de um serviço no Docker —, a função é `undefined`, o handler do
 * formulário morria com TypeError e o botão de registrar pagamento não fazia
 * nada. Nenhum aviso na tela, porque o erro acontecia antes do envio.
 *
 * Quem mostrou foi o teste de ponta a ponta, que chega por
 * `http://frontend:3000` e não por localhost.
 *
 * `crypto.getRandomValues` não tem essa restrição, então o caminho alternativo
 * monta o UUID v4 com ele — aleatoriedade criptográfica nos dois casos, e
 * nenhuma dependência nova.
 */
export function novaChaveDeIdempotencia(): string {
  if (typeof crypto.randomUUID === "function") {
    return crypto.randomUUID();
  }

  const bytes = crypto.getRandomValues(new Uint8Array(16));

  // Versão 4 e variante RFC 4122, os mesmos bits que o randomUUID grava.
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;

  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0"));

  return [
    hex.slice(0, 4).join(""),
    hex.slice(4, 6).join(""),
    hex.slice(6, 8).join(""),
    hex.slice(8, 10).join(""),
    hex.slice(10, 16).join(""),
  ].join("-");
}
