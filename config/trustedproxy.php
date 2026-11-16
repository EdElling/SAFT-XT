<?php

declare(strict_types=1);

/*
 * Vertrauenswürdige Proxys (Load Balancer, Reverse Proxy des Hosters).
 *
 * Endet HTTPS vor dem Server, kommt die Anfrage dort als HTTP an. Nur wenn SAFT dem Proxy vertraut,
 * übernimmt es dessen Angaben (X-Forwarded-Proto/-For/-Host/-Port): Links und Weiterleitungen mit
 * https://, die echte IP-Adresse im Protokoll und bei der Sperre nach Fehlversuchen.
 *
 * TRUSTED_PROXIES leer: keinem Proxy vertrauen (Vorgabe, richtig ohne Proxy).
 * Adressen oder Bereiche, kommagetrennt: z. B. „10.0.0.5“ oder „10.0.0.0/8,192.168.0.0/16“.
 * „*“: dem direkt anfragenden Rechner vertrauen – nur, wenn der Server ausschließlich über den Proxy
 * erreichbar ist, sonst könnten Angreifer IP-Adresse und Protokoll vortäuschen.
 */
return [
    'proxies' => ($proxies = trim((string) env('TRUSTED_PROXIES', ''))) === '' ? null : $proxies,
];
