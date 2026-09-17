<?php

namespace Tests\Fixtures;

/**
 * Par RSA estático para testes (Windows sem openssl.cnf não gera chave em runtime).
 */
final class OktoRsaFixture
{
    /**
     * @return array{private: string, public: string}
     */
    public static function pair(): array
    {
        return [
            'private' => <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQC99TXMlcLENtY0
l+KtfcwlNLUEqO6Fxsl9Q3ranDdmPLObw+tkHdrxAPO0MIvotwNFLr9fPMzCWJJe
vfUJqm3RD0ZFb4UzBK382q6HgEP981vowqnmPmanmOjnYYKRqOatkdJXdCaiT+Ol
TY2zCgfA5bJ7lWkSx4y1vFW3Etdf/PEc0FDlOJACQYD0/xXMyurwSFnOhAvHfWDj
mH6KtEeFiO+/3irk/ssoXW143QfyufVDxhyRjM2p67qw2hjQxT9golIme1Lxo9ad
bOMnVq4pN24MTS5yHQ5sn5RW2I8+BG937plV5u+Pra6P/oV6HAZ1dSwN6JOe0nWZ
mR7LrDAXAgMBAAECggEADYnJmP64PNrzqH5StEhM7mUaEAgolI9p+NJNK5txAomJ
yEY0VpRSI4imPDQuV7QDbA2i3s5eGyCY5F7n23wY2FeDi86HUO8g2O6OlcjfqULy
f/cWImGI8dTrp67DBLXKJJpJ9XWpWaDiYC7UAVI4fAti2kmRwLoVuALn0P5rK/bW
ZsNeKOSsMBi0eD3uQcN/ka23kftHVjPW35Or3xu8md/BWvO8iSjTWPM1OORRjzHj
SW9yV+Z6TU+gNX+9gatWEBNJYeZLF33P1fXNJt3dTConUo9zdKHE6gFCoJ6R9lox
nhCzNTl598Vw9wTwrNEliTH4bD43GI6x2Mg0oNy+oQKBgQDgHCYtIwQkyLH/ro2T
9RgcPTXIdcNXKep0n1E32Xv9HLccql1z5Q4kQUEirXlwWDiEWRhvfPzftNps/d0c
0SbK5nonmTV9qOkAUVrwg4moLn4W5ZaDUjVnw7p8WZgibMMstOHuEck8aC1juNdl
zcTyjaTWc7c6nVPYq6MlEzYB7wKBgQDY/PjhxtQudGt/YbdT857T8MKndkfsPea+
NzRBQaEOiTPzxuQqa+HZCDTWRBSv2wjhLg2D/Nyg6HGS3yqu+FDtLRDekOLQ/7gW
9jzg9HICmgGEwba2ICqm6cz3FroRekatcve6Zbsh6K3UsRO3X/JdMuKCB4ipP0Ou
vPWGhSa8WQKBgC1phtLepZhOksMcu9OfdqNCRAO62TpwY/H91pdamqVPjEtiuk0h
vRvbnTdJr7H0Ln+jDjCJQzSRkTFEv+l2+EVlLpuXkB9GevB1i9fwz5Qk16gMHdO+
dNPx9Xf9L7bKE0Kb5Kw5Lm3vLkNm0T7v01jTGvPZvudBuhvNq+F3YxpBAoGAagQP
RaBzgs72xqHjhGz/KOX09QThVxdXaZBnQ4rhOcznSS/fwqo7CmLsDdPtl44Y5Iwv
plEhKqzm8K+Al0RTpc3i9Bst9pc6Rl3AmNhV69d67nYG4y0MKckJj5/XATsQ1SXa
y4Nwzrx0UfrCe1GxhL+b05QCvU5frzw7aaIcruECgYB7z5cvGXATIt0RrtD8AX0P
3JIN0ic+fPK/NCcMEFn2XL8O3rah8BcxRaOeg0gL2JC4+Rpf+QIOKZf5nBEfhmqh
WUWgKBK6rfl9xqtEy6P4mhpfGofETkaoS5kS4kfwQIS3x67TjMZZoeEtXcm7XOzk
FHvzXZ8BCr/2bOlTEsnhbQ==
-----END PRIVATE KEY-----
PEM,
            'public' => <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAvfU1zJXCxDbWNJfirX3M
JTS1BKjuhcbJfUN62pw3Zjyzm8PrZB3a8QDztDCL6LcDRS6/XzzMwliSXr31Capt
0Q9GRW+FMwSt/Nquh4BD/fNb6MKp5j5mp5jo52GCkajmrZHSV3Qmok/jpU2NswoH
wOWye5VpEseMtbxVtxLXX/zxHNBQ5TiQAkGA9P8VzMrq8EhZzoQLx31g45h+irRH
hYjvv94q5P7LKF1teN0H8rn1Q8YckYzNqeu6sNoY0MU/YKJSJntS8aPWnWzjJ1au
KTduDE0uch0ObJ+UVtiPPgRvd+6ZVebvj62uj/6FehwGdXUsDeiTntJ1mZkey6ww
FwIDAQAB
-----END PUBLIC KEY-----
PEM,
        ];
    }
}
