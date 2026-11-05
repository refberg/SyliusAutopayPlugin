# Sylius Autopay Plugin

[English version](README.md)

Bramka płatności online Autopay (dawniej Blue Media) dla Sylius 2, oparta na Sylius Payment Requests.
Nie korzysta z Payum.

## Funkcje

- Strona płatności Autopay (przekierowanie) w checkoucie sklepu i w Sylius API. Transakcja jest rozpoczynana
  po stronie serwera, więc kwota nie przechodzi przez przeglądarkę.
- Stan płatności zmienia się dopiero po odczycie transakcji z API Autopay i sprawdzeniu jej z płatnością Sylius
  (identyfikator serwisu, OrderID, kwota i waluta). ITN (powiadomienie Autopay) tylko uruchamia to sprawdzenie,
  a powrót klienta z Autopay nigdy nie jest dowodem zapłaty.
- ITN i ISTN są weryfikowane dwa razy (hash porównywany przez `hash_equals`), a odpowiedź to podpisane potwierdzenie,
  którego oczekuje Autopay.
- Powtórzone i spóźnione ITN-y nie zmieniają wyniku i nigdy nie opłacają zamówienia dwa razy.
- Pełne zwroty z panelu administracyjnego. Ich status przychodzi w powiadomieniach ISTN albo odczytuje go polecenie
  konsoli; zwroty zrobione w panelu Autopay są synchronizowane przez ISTN.
- Anulowanie płatności w Syliusie anuluje jej otwarte transakcje w Autopay.
- Strona zamówienia w panelu admina pokazuje przy płatności transakcję Autopay (OrderID, RemoteID, status), zwroty i ostrzeżenia.
- Dane dostępowe są szyfrowane przez Syliusa i nie trafiają do logów.

Poza zakresem: wybór kanału płatności (bramki) w sklepie, BLIK level 0, karty i płatności cykliczne,
zwroty częściowe zlecane z Syliusa.

Plugin opiera się na zachowaniu Autopay, które częściowo nie jest jeszcze potwierdzone; fakty, założenia (A1–A5)
i sposób ich sprawdzenia opisuje [docs/autopay.md](docs/autopay.md).

## Wymagania

| Sylius  | PHP     | Symfony                                   |
|---------|---------|-------------------------------------------|
| 2.2.12+ | 8.2–8.4 | 6.4, 7.4                                  |
| 2.3.2+  | 8.3–8.5 | 6.4, 7.4, 8.x (8.x wymaga PHP 8.4+)       |

Sklep (`SyliusShopBundle`), panel administracyjny (`SyliusAdminBundle`) i Twig Hooks są opcjonalne, więc plugin
działa też w aplikacjach udostępniających tylko API.

Maszyna stanów `sylius_payment` musi używać adaptera Symfony Workflow (domyślnego w Syliusie). Zwroty i anulowania
są wysyłane do Autopay z listenerów zdarzeń `workflow.sylius_payment.completed.refund`
i `workflow.sylius_payment.completed.cancel`. Przy adapterze winzou dla `sylius_payment`
(`winzou_state_machine` w `sylius_state_machine_abstraction.graphs_to_adapters_mapping` lub `default_adapter`)
zwroty z Syliusa nie trafiają do Autopay i trzeba je wykonać w panelu Autopay.

## Instalacja

1. Zainstaluj pakiet:

   ```bash
   composer require refberg/sylius-autopay-plugin
   ```

2. Zarejestruj bundle w `config/bundles.php`, jeśli nie zrobił tego Symfony Flex:

   ```php
   Refberg\SyliusAutopayPlugin\RefbergSyliusAutopayPlugin::class => ['all' => true],
   ```

3. Upewnij się, że istnieje klucz szyfrowania konfiguracji bramek (Sylius tworzy go przy instalacji):

   ```bash
   bin/console sylius:payment:generate-key
   ```

4. Tylko w aplikacjach bez sklepu: zaimportuj trasę powiadomień o płatnościach, którą Sylius w innym przypadku
   importuje razem z trasami sklepu, w `config/routes/sylius_payment.yaml`:

   ```yaml
   sylius_payment_notify:
       resource: "@SyliusPaymentBundle/Resources/config/routing/integrations/sylius.yaml"
   ```

   Bez tej trasy Autopay nie dostarczy ITN-ów, a w aplikacji z panelem administracyjnym nie wyświetli się strona edycji
   metody płatności Autopay (pokazuje adres ITN). Klienci API muszą też przesłać `return_url` w payloadzie (zob. [API](#api)).

5. Jeśli Autopay nie wysyła ISTN dla Twojego serwisu (zob. [Konfiguracja](#konfiguracja)), zaplanuj sprawdzanie
   statusu zwrotów, np. co godzinę:

   ```bash
   bin/console refberg:autopay:refresh-refunds
   ```

Migracje bazy nie są potrzebne. Plugin trzyma swoje dane w `sylius_payment.details`.

## Konfiguracja

W panelu administracyjnym wybierz **Konfiguracja → Metody płatności → Utwórz → Autopay** i uzupełnij:

| Pole              | Nazwa w Autopay                                              |
|-------------------|--------------------------------------------------------------|
| Środowisko        | testowe (`testpay.autopay.eu`) lub produkcyjne (`pay.autopay.eu`) |
| Identyfikator serwisu | `ServiceID`                                              |
| Klucz współdzielony | klucz współdzielony serwisu                                |
| Funkcja skrótu    | funkcja skrótu serwisu (domyślnie SHA-256)                   |

Klucz współdzielony nie jest pokazywany po zapisaniu. Zostaw pole puste, aby nie zmieniać zapisanej wartości.

Autopay wysyła ITN na adres ustawiony dla identyfikatora serwisu, a nie na adres przekazany z transakcją.
Przekaż Autopay adres `https://<twój-sklep>/payment-methods/<KOD_METODY_PŁATNOŚCI>`; widać go na stronie edycji
metody płatności. Domena musi mieć publicznie zaufany certyfikat z pełnym łańcuchem i TLS 1.2 lub 1.3.

Poproś Autopay także o włączenie **ISTN** (powiadomień o rozliczeniach i zwrotach, najlepiej ze wszystkimi statusami)
na ten sam adres. Dzięki ISTN plugin od razu poznaje wynik zwrotów i widzi zwroty zrobione w panelu Autopay.
Domyślnie ISTN jest wyłączone.

### Parametry kontenera

| Parametr                               | Domyślnie | Znaczenie                                                                 |
|----------------------------------------|-----------|---------------------------------------------------------------------------|
| `refberg_autopay.http_timeout`         | `15.0`    | Limit bezczynności zapytań do Autopay, w sekundach.                       |
| `refberg_autopay.transaction_validity` | `0`       | Ile sekund można opłacić rozpoczętą transakcję (`ValidityTime`, maks. 31 dni); `0` zostawia domyślne 6 dni Autopay. |

```yaml
parameters:
    refberg_autopay.http_timeout: 30.0
    refberg_autopay.transaction_validity: 86400
```

Ruch do Autopay idzie przez serwis `refberg_autopay.http_client` (alias `http_client`), który można nadpisać,
np. żeby użyć proxy.

### Metoda płatności tworzona poza panelem administracyjnym

Metoda Autopay tworzona w fixture'ach, migracjach lub kodzie musi mieć `usePayum` ustawione na `false`.
Sylius domyślnie ustawia `true`; formularz w panelu wyłącza to sam dla bramek bez fabryki Payum. Przy `usePayum: true`
sklep przekazuje płatność do Payum zamiast do pluginu, więc strona płatności nie działa, a Sylius nie szyfruje
konfiguracji bramki.

```yaml
sylius_fixtures:
    suites:
        default:
            fixtures:
                payment_method:
                    options:
                        custom:
                            autopay:
                                code: AUTOPAY
                                name: Autopay
                                gatewayName: autopay
                                gatewayFactory: !php/const Refberg\SyliusAutopayPlugin\AutopayGateway::FACTORY_NAME
                                gatewayConfig:
                                    !php/const Refberg\SyliusAutopayPlugin\AutopayGateway::CONFIG_ENVIRONMENT: sandbox
                                    !php/const Refberg\SyliusAutopayPlugin\AutopayGateway::CONFIG_SERVICE_ID: '...'
                                    !php/const Refberg\SyliusAutopayPlugin\AutopayGateway::CONFIG_SHARED_KEY: '...'
                                    !php/const Refberg\SyliusAutopayPlugin\AutopayGateway::CONFIG_HASH_ALGORITHM: sha256
                                usePayum: false
                                enabled: true
                                channels: [FASHION_WEB]
```

Przykład w kodzie znajdziesz w [README.md](README.md#creating-the-payment-method-outside-the-admin-panel).

## Jak to działa

### Sklep

1. Klient składa zamówienie i klika **Zapłać**. Plugin rozpoczyna transakcję Autopay w tle (każda próba dostaje
   nowy `OrderID`) i przekierowuje klienta na stronę Autopay.
2. Po płatności klient wraca do sklepu (`/order/after-pay/{hash}`). Plugin odczytuje status transakcji z API Autopay;
   parametry adresu powrotu są ignorowane. Dopóki Autopay przetwarza płatność, klient widzi stronę podziękowania,
   a płatność pozostaje nieopłacona.
3. Autopay wysyła ITN na `/payment-methods/{code}`. Płatność jest kończona albo oznaczana jako nieudana na podstawie
   stanu odczytanego z API Autopay.

Ponowne otwarcie strony płatności używa trwającej transakcji zamiast rozpoczynać nową.

### API

```http
POST /api/v2/shop/orders/{tokenValue}/payment-requests
Content-Type: application/ld+json

{
    "paymentId": 123,
    "paymentMethodCode": "AUTOPAY",
    "payload": {"return_url": "https://app.example/payment/return"}
}
```

`responseData.redirect_url` to strona Autopay. `return_url` jest opcjonalny, gdy sklep jest zainstalowany; bez sklepu
żądanie bez `return_url` kończy się błędem, a Autopay nie jest wywoływany. Status sprawdzisz żądaniem z `"action": "status"`:
`responseData.payment_state` to stan płatności w Syliusie, `responseData.autopay_status` status w Autopay
(`PENDING`, `SUCCESS` lub `FAILURE`).

### Zasady zmiany stanu płatności

| Status OrderID w Autopay | Płatność w Syliusie                                                    |
|--------------------------|------------------------------------------------------------------------|
| `PENDING`                | bez zmian (`new`)                                                      |
| `SUCCESS`                | `completed`, jeden raz                                                 |
| `FAILURE`                | `failed`, tylko gdy żadna inna transakcja tej płatności nie jest otwarta |

Klient może na stronie Autopay ponowić próbę dla tego samego `OrderID`. `OrderID` ma status `SUCCESS`, gdy którakolwiek
próba się powiodła, a `FAILURE` tylko wtedy, gdy wszystkie się nie powiodły. Gdy opłacono więcej niż jedną próbę tego
samego `OrderID`, płatność jest kończona raz i dostaje ostrzeżenie `duplicate_payment`.

Opłacona płatność nigdy nie wraca do wcześniejszego stanu. Gdy opłacona zostanie druga transakcja tej samej płatności,
zamówienie nie jest opłacane drugi raz: plugin zapisuje ostrzeżenie `duplicate_payment` w szczegółach płatności
(`refberg_autopay.warnings`) i loguje błąd na kanale `autopay`. Taką transakcję zwróć ręcznie w panelu Autopay.

Gdy klient po przekierowaniu do Autopay zmieni metodę płatności, transakcja rozpoczęta wcześniej pozostaje powiązana
z płatnością. Jej ITN albo powrót klienta z Autopay kończy płatność, gdy API Autopay potwierdzi wpłatę; plugin przywraca
opłaconą metodę Autopay, zapisuje ostrzeżenie `paid_with_previous_payment_method` i loguje ostrzeżenie, więc zwroty
z Syliusa trafiają do Autopay. Żądanie `status` przez API z nową metodą sprawdza tylko transakcje jej identyfikatora serwisu.

ITN bez poprawnego hasha albo innego serwisu dostaje HTTP 400 i nie jest zapisywany; ITN nieznanej transakcji dostaje
HTTP 404. Gdy API Autopay jest niedostępne, odpowiada HTTP 5xx albo odrzuca dane dostępowe pluginu, endpoint odpowiada
HTTP 5xx i Autopay ponawia ITN. **Działa to tylko przy synchronicznym transporcie payment requestów**
(`SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN=sync://`, domyślnie w Syliusie): przy transporcie asynchronicznym ITN jest
potwierdzany przed przetworzeniem i Autopay go nie ponowi. Pozostałe błędy (np. niezgodna kwota) są trwałe: ITN jest
potwierdzany, a przyczyna zostaje zapisana w payment requeście.

### Zwroty

Kliknij **Zwrot** przy opłaconej płatności Autopay w panelu. Plugin zleca zwrot kwoty płatności pomniejszonej
o nieanulowane zwroty (także te z panelu Autopay). `MessageID` powstaje z identyfikatora payment requestu, więc
ponownie dostarczone żądanie nie zwraca pieniędzy drugi raz.

Autopay realizuje zwroty asynchronicznie (do 30 minut), a przyjęty zwrot może się jeszcze nie udać. Wynik plugin
zapisuje z ISTN, jeśli Autopay je wysyła, albo odczytuje poleceniem `bin/console refberg:autopay:refresh-refunds`
(opcja `--days`, domyślnie 30).

Sylius od razu oznacza płatność jako zwróconą. Jeśli Autopay odrzuci zwrot, payment request zwrotu jest oznaczany jako
nieudany i logowany jest błąd; jeśli zwrot nie uda się później (ISTN `FAILURE` albo status `CANCELED_*`), plugin zapisuje
go jako anulowany i loguje błąd. W obu przypadkach płatność pozostaje `refunded` – zwróć pieniądze ręcznie w panelu
Autopay. Sylius nie ponawia nieudanego żądania automatycznie, także gdy Autopay był niedostępny.

### Zwroty zrobione w panelu Autopay

Przy włączonym ISTN zwrot opłaconej transakcji, którego Sylius nie zlecał, jest zapisywany w szczegółach płatności jako
zewnętrzny (`external`). Gdy zrealizowane zwroty pokryją całą kwotę, płatność przechodzi w `refunded` bez wysyłania
kolejnego zwrotu do Autopay (payment request zwrotu kończy się z `covered_by_autopay_panel_refunds`). Zwroty częściowe
widać tylko w szczegółach płatności, bo Sylius nie ma dla nich stanu. Zwrot z Syliusa po częściowym zwrocie z panelu
prosi o pozostałą kwotę.

Bez ISTN plugin nie widzi zwrotów z panelu: zwrot z Syliusa prosi wtedy o pełną kwotę, którą Autopay odrzuca, gdy
przekracza to, co zostało; żądanie kończy się błędem i zwrot trzeba dokończyć w panelu.

### Anulowanie

Gdy płatność Autopay zostanie anulowana w Syliusie (np. przy anulowaniu zamówienia), plugin anuluje każdą jej transakcję,
którą można jeszcze opłacić (`transactionCancel`). Jeśli Autopay zgłosi, że transakcja była już opłacona
(`CANCELED_PARTIALLY`, `INCORRECT_PAYMENT_STATUS`), payment request anulowania jest oznaczany jako nieudany i logowany jest
błąd; późniejszy ITN zapisuje ostrzeżenie `payment_completed_after_failure`, a płatność nie jest kończona. Zwróć ją
w panelu Autopay. Transakcji innego serwisu Autopay (rozpoczętych przed zmianą metody) nie da się anulować bieżącymi
danymi; dostają ostrzeżenie `transaction_not_cancelled_at_autopay`.

## Ograniczenia

- Maszyna stanów `sylius_payment` musi używać adaptera Symfony Workflow (zob. [Wymagania](#wymagania)).
- Ponawianie ITN-ów po błędach przejściowych wymaga transportu `sync://`.
- Przy transporcie asynchronicznym ustaw `framework.router.default_uri` na publiczny adres sklepu, żeby adres powrotu
  był poprawny.
- Zwroty z panelu Autopay są synchronizowane tylko przy ISTN włączonym przez Autopay.
- Modele, w których Autopay dolicza prowizję do kwoty transakcji, nie są obsługiwane.
- Płatność, której metodę po przekierowaniu zmieniono na metodę spoza Autopay i którą potem anulowano w Syliusie,
  nie jest anulowana w Autopay; sprawdź starą transakcję w panelu Autopay.

## Bezpieczeństwo

- Sekrety są przechowywane w konfiguracji bramki, którą Sylius szyfruje.
- Żądania zwrotu i anulowania działają tylko dla płatności już zwróconej lub anulowanej w Syliusie, więc nie da się ich
  wywołać przez API sklepu.
- Klucz współdzielony i hashe nie trafiają do logów ani danych payment requestów. Komunikaty wyjątków zawierają tylko
  statusy HTTP i nazwy błędów Autopay.
- XML od Autopay jest parsowany bez dostępu do sieci, a dokumenty z DTD są odrzucane.

## Rozszerzanie i rozwój

Lista serwisów do dekorowania (ID = interfejs) oraz polecenia do testów i analizy kodu są w [README.md](README.md#extending).
Testy w środowisku testowym Autopay: `AUTOPAY_SANDBOX=1 AUTOPAY_SANDBOX_SERVICE_ID=... AUTOPAY_SANDBOX_SHARED_KEY=...
vendor/bin/phpunit --testsuite=sandbox`; scenariusze ręczne opisuje [docs/autopay.md](docs/autopay.md).

## Licencja

MIT, zob. [LICENSE](LICENSE).
