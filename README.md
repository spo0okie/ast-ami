# Коллектор событий Asterisk
отлавливает события asterisk через AMI интерфейс и отправляет их на какой-либо бэкенд

## Цепочка взаимодействия следующая
* asterisk генерирует ивент
* сервис amiConnector через ами интерфейс библиотеки phpagi получает его
* amiconnector передает их в класс chansList (список каналов)
* переданные события обрабатываются процедурой chanlist->upd
* из параметров ивента определяется к каком каналу относится ивент, при необходимости канал создается, если канал уже известен, то он обновляется (постепенно каждый канал собирает из поступающих ивентов полную о себе информацию)
* информация об изменении статуса канала отправляются на обработку во внешний коннектор (оракл/вебсокеты/консоль/HTTP API)
* внешний коннектор анализирует полноту данных канала и принимает решение отправлять ли данные во внешнюю ИС

## Каналы
Каналы создаются на основе событий, также на основе событий они переименовываются и уничтожаются
