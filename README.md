# FourDimensions
仮想チャンクを作成するPMMPプラグインです。
config.ymlでチャンクのXYの範囲をしてすると(座標のxyではなくチャンクのXY)、その範囲のチャンクはプレイヤーが自分だけから見え、自分のみが編集できる土地となります。

絶対にDEFAULTワールドで実行しないでください、必ずFLATでご利用ください。

コマンド:
/vland info : その場のチャンクのXYを確認できます。あと設定も確認できます。
/vland default set : このコマンドは、生成する仮想チャンクのデフォルトの地形に、そこに元あった地形を使用するというコマンドです。このコマンドを実行しないと、空気で満たされたチャンクが生成されます。このコマンドを実行することをお勧めします。

Config:
is_virtual_chunk_enabled : 仮想チャンクを有効にするか=このプラグインを有効にするかです。
virtual_chunk_left_x : ChunkのXの左端(小さいほう)
virtual_chunk_right_x : ChunkのXの右端(大きいほう)
Zについても同様

virtual_chunk_max_height : 最大の高さ。必ず16の倍数にしてください。
virtual_chunk_enabled_level : 仮想チャンクを有効にするワールド名

これから実装する予定のこと:
Tileを動くように
他人の土地に行けるように(時空移動)
