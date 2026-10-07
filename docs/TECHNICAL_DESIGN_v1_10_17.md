# TECHNICAL DESIGN — v1.10.17 · Analisi di rete dell'handshake

```
3 Trasporto/TLS ── errno 7 | 28 ──► WpAtsDiag::network()
   3b Rete            fsockopen(ip, porta, 5 s) e (ip, 80) per i primi 3 IP (o IP forzato) → aperta | nessuna risposta | rifiutata
   3c Host alternativo  gethostbynamel(www.<host> | <host> senza www) + fsockopen
   3d Proxy           getenv(HTTPS_PROXY, HTTP_PROXY, ALL_PROXY), Windows: netsh winhttp show proxy
```
Rimedi del passo 3b:
- porta aperta → proxy, ispezione TLS o timeout;
- solo la 80 aperta → HTTPS non pubblicato o filtrato;
- nessuna porta aperta → firewall in uscita o proxy obbligatorio. Se il sito è nella rete aziendale, impostare l'IP forzato (interno).

`WpAtsClient`: nuovo parametro `resolveIp` (`wpats.resolve_ip`) → `CURLOPT_RESOLVE ["host:porta:ip"]`. Viene applicato a tutte le chiamate (test, invio, prelievo, CV, ack). `WpAtsConfig::save` valida l'IP con `FILTER_VALIDATE_IP`.
