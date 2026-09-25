# Strategy の操作別インターフェース設計

状態: 設計案。以下は変更後の契約を記述しており、現在の実装の説明ではない。

## 目的

Strategy は get / fetch / set を包む Middleware とする。実処理の入力に加え、どのキャッシュ境界で発生した操作かを参照できるようにする。単体でも合成後でも同じインターフェースを使い、Strategy が 0 件の場合は Runtime が実処理を直接呼ぶ。

Operation は操作の情報を保持する。origin、next、時計、Observer、APM クライアント、実行・通知メソッドは保持しない。Strategy が必要とするサービスは、その Strategy のコンストラクター依存とする。

## Strategy と next の契約

```php
interface CacheStrategy
{
    /**
     * @param Closure(CacheGetOperation): (Cached<mixed>|null) $next
     * @return Cached<mixed>|null
     */
    public function get(CacheGetOperation $operation, Closure $next): ?Cached;

    /**
     * @param Closure(CacheFetchOperation): Cached<mixed> $next
     * @return Cached<mixed>
     */
    public function fetch(CacheFetchOperation $operation, Closure $next): Cached;

    /**
     * @param CacheSetOperation<mixed> $operation
     * @param Closure(CacheSetOperation<mixed>): bool $next
     */
    public function set(CacheSetOperation $operation, Closure $next): bool;
}
```

各 next は同種の Operation だけを受け取る。Middleware は変更した Operation を `$next($operation)` に渡し、戻り値を加工し、委譲した処理の例外を扱える。委譲せず独自の結果を返すこともできる。

値や Metadata を変更する場合も、そのキャッシュ境界の値型 T を維持し、宣言した Metadata の変更範囲を守る。

`get` の `null` はキャッシュミスを表す。`Cached::of(null)` は null という値のヒットなので区別する。鮮度は get から戻った Cached の Metadata を使って Runtime が判断する。

fetch の next が Operation を受け取るのは Middleware 間の情報伝達のためである。Runtime が実際の origin を呼ぶ際は、従来どおり `$origin()` と引数なしで実行する。Operation の引数情報でクロージャーの捕捉変数を書き換える機構は作らない。

## 呼び出し情報

3 種類の Operation は共通の読み取り用データ `CacheCall` を参照する。この名前は、1 回の cached 呼び出しの出所と設定を表す。origin クロージャーを含む Runtime 入力である現在の `CacheInvocation` とは分ける。

| 情報 | 意味 |
| --- | --- |
| `class` | 呼び出された具体クラス |
| `declaringClass` | 対象メソッドを宣言したクラス |
| `method` | cached を直接呼んだメソッド |
| `arguments` | パラメーター名に対応付けた、キー変換前の引数値 |
| `key` | この呼び出しについて Runtime が生成した境界キー |
| `runtime` | 選択された Runtime の登録名 |
| `policy` | Attribute とパラメーター設定の解決後、操作開始時点の CachePolicy |
| 宣言された追加設定 | パラメーター TTL、DynamicTtl / BypassCacheErrors の宣言、実行する Strategy の構築定義など |

追加設定にも型の付いた既存の宣言データを使う。Reflection オブジェクト、サービスインスタンス、実行用の CacheDefinition / CacheInvocation は公開しない。Attribute の無効化・優先順位を解決した、実際に採用される設定を提供する。

`arguments` は `CacheIgnore` で除外される前、`CacheKey` で変換される前の値を保持する。省略時の既定値や可変長引数の対応付けは既存の CallArguments と統一する。現在の CacheKeyContext.arguments はキー専用の変換済み情報なので、そのまま流用しない。

呼び出しごとのデータは、その呼び出しの中だけで保持する。メモ化する宣言や構築定義に引数値を格納しない。オブジェクト引数を任意に複製・シリアライズすることもしないため、読み取り専用の入れ物は引数オブジェクト自体の不変性を保証しない。

CacheCall は呼び出しの出所を表す。Middleware が操作の対象キーや書き込み内容を変更しても、この記録は変更しない。元の境界キーと、実際の操作の対象キーを両方参照できる。

## 各 Operation

### CacheGetOperation

- `call: CacheCall` — 呼び出しの出所と設定。
- `key: string` — 今回読み取る対象キー。初期値は call.key。
- `withKey(string): self` — 対象キーを変更した新しい Operation を返す。

取得前の要求なので、取得結果や取得後にしか判明しない保持期限は持たせない。

### CacheFetchOperation

- `call: CacheCall` — 問い合わせが属する呼び出しの出所と設定。

業務上の origin 引数はない。キーを使う期限分散や APM 計測は call.key を参照できる。fetch 固有の入力が増えない限り、get の key や set の保存内容を共通化して持たせない。

宣言された TTL と、取得後の Cached.metadata.expiresAt は別の情報である。動的 TTL の計算結果や依存先から伝播する Metadata を、取得前に確定済みとして Operation に入れない。

### CacheSetOperation<T>

- `call: CacheCall` — 呼び出しの出所と設定。
- `key: string` — 今回の書き込み先キー。初期値は call.key。
- `cached: Cached<T>` — 保存要求の値と確定済み Metadata。
- `retainedUntil: ?float` — 任意の物理保持期限。未指定なら Cached の expiresAt を使う。
- `withKey(string): self<T>` — 書き込み先を変更する。
- `withCached(Cached<T>): self<T>` — 保存する値と Metadata を変更する。
- `withRetainedUntil(?float): self<T>` — 物理保持期限を変更する。

これらは新しい Operation を返し、受け取った Operation を変更しない。現在の CacheWrite の役割をこの操作型に統合する。

既に絶対期限を持つ Cached と別に相対 TTL を保持すると、どちらが保存条件か曖昧になる。期限の指定は Metadata.expiresAt と retainedUntil に統一し、PSR バックエンド向けの相対 TTL への変換は保存時に行う。物理保持期限は論理的な鮮度を延長しない。

保存内容を変更した後も、有限性と「物理保持期限は論理期限より前にならない」という既存の制約を守る。期限を変更する Middleware は、必要に応じて保持期限も一緒に指定した新しい Operation を構築できる。

set の bool は、受け取った保存要求が成功したかを表す。

| 状況 | 結果 |
| --- | --- |
| 保存先が書き込み要求を受理した | `true` |
| NoStore・期限切れ・期限未確定などで保存しない | `false` |
| Middleware が保存を省略する | 原則 `false`。独自の保存先で要求を満たしたならその成否 |
| 設定された backend bypass によって書き込み失敗を無視する | `false` |
| 回復しない宣言済みの例外 | 同じ例外を伝播する |

false と例外は区別する。Runtime は保存が false でも取得済みの Cached を返す。APM 用 Middleware は、委譲した set の bool や例外を観測できる。

## 合成と Runtime

ComposedCacheStrategy が操作ごとの Closure を直接入れ子にする。例えば fetch の合成の中身は次の形になる。

```php
foreach (array_reverse($strategies) as $strategy) {
    $next = static fn (CacheFetchOperation $operation): Cached
        => $strategy->fetch($operation, $next);
}

return $next($operation);
```

get と set も、それぞれの Operation と戻り値型で同じ合成を行う。子が 0 件なら渡された next をそのまま実行する。合成をさらに合成してもインターフェース・委譲順序・例外の捕捉範囲は変わらない。

Runtime の順序は次のとおりとする。

1. 宣言・呼び出し引数を解決し、境界キーと CacheCall を作る。
2. 呼び出し専用の Strategy インスタンスを構築する。
3. 読み取り対象なら CacheGetOperation を実行し、戻った Cached の鮮度を判断する。
4. 新鮮なヒットならその Cached を返す。
5. CacheFetchOperation を実行する。実処理は引数なしで origin を呼び、ローカル設定を適用する。
6. 得られた Cached から CacheSetOperation を作って実行する。
7. Cached を返す。

Strategy 未指定時は、それぞれの実処理を直接実行する。実処理を CacheStrategy に見立てたり、終端用の Strategy を挿入したりしない。

呼び出し情報を追加してもキー計算は変更しない。キーから除外した引数を観測することと、その引数でキャッシュされる値を変えることは区別する。Strategy が返す値を変える場合も、同じキーで結果を共有できるというキー宣言の条件を満たす必要がある。

## APM 用 Middleware

APM 用 Strategy は、Operation の call から具体クラス・宣言クラス・メソッド・引数・境界キー・設定を取得できる。get / set では現在の対象キーも参照できる。

その Strategy が観測した処理時間、戻り値、例外を組み合わせ、どのメソッドのどのキャッシュ操作だったかを記録する。計測と送信は Strategy が行い、Runtime や Operation に APM 固有のイベント分類を追加しない。APM に送る属性の選択も、その Strategy の責務とする。

APM クライアントなど任意のコンストラクター依存を渡すため、Runtime の構築時に Strategy の構築 factory を指定できるようにする。既存の `StrategyDefinition::instantiate($factory)` の経路を利用し、構築するクラスと解決済みの設定引数を application 側の factory に渡す。未指定時は現在の標準 factory を使う。factory は呼び出しごとに新しい Strategy を作り、APM クライアントなどのサービスを渡す。実行可能な Strategy インスタンスをメモ化する経路にはしない。

合成順序によって観測範囲は変わる。外側に置けば内側の Middleware を含む処理を観測し、内側に置けばそれより外の処理は観測しない。fetch が返ったことだけから、実際の origin が呼ばれたと判定しない。

## get を ?Cached にすることで変わる保持期限の扱い

現在の CacheRead は Cached に加えて retainedUntil を返す。StaleIfError はこれを保持し、origin の失敗時にも物理保持期限を再確認している。

`get(): ?Cached` を採用すると、値の Metadata に含まれない物理保持期限は返らなくなる。この設計では次の役割分担を提案する。

- 保存先の物理保持期限は get の実処理で判定する。取得時点で保持期限を過ぎていれば null。
- 取得できた値を StaleIfError が使える期間は、その Strategy の expiresAt と maxAge で判定する。
- 取得後に保存先の保持期限を過ぎても、その期限だけで取得済みの値を無効化しない。

これは単なる型の置き換えではなく、取得から origin の失敗までに物理保持期限をまたぐ場合の挙動変更である。例えば expiresAt=90、retainedUntil=105、maxAge=30 の値を時刻100に取得して時刻110に失敗した場合、現在は拒否するが、この案では maxAge の範囲内なので利用できる。

現在の再確認を維持するなら、保持期限を応答として伝える契約が必要になる。その場合は `?Cached` だけでは情報が足りない。要求である Operation に取得結果を書き戻したり、Runtime と Strategy の間に隠れた参照表を設けて解決しない。この意味論の選択は、実装変更の前に明示する。

## 実装への影響と検証

- Cacheable / CacheDefinition / CacheInvocation: キー変換前の引数と有効な宣言情報を、呼び出しごとのデータとして引き渡す。
- CacheStrategy / ComposedCacheStrategy: 各 Operation と、同じ Operation を受ける next に移行する。
- CacheExecution / GuardedCache: get の Cached 変換と set の bool 伝播を整える。保存を省略した経路も false として伝える。
- Runtime の構築: 任意のコンストラクター依存を渡せる Strategy 構築 factory の指定を追加する。
- 個別 Strategy: Operation の情報を使う。StaleIfError の状態は引き続きそのインスタンスが持つ。
- CLI: メタデータの合成則・上書き順序は変えない。入力型の変更に合わせた Fixture と宣言の読み取りを確認する。
- 検証: 0件・空・入れ子の合成、Operation の置き換え、元引数とキー用引数の区別、null 値のヒットとミス、set の true / false / 例外、APM 相当の呼び出し情報の観測、引数なしの origin、呼び出し間の状態分離、保持期限をまたぐ場合、既存の Metadata Bubbling の行列を確認する。
