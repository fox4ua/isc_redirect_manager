ISC Redirect Manager

Что улучшено в этой сборке:
- дубликаты активных правил с одинаковым bundle/field/condition/value запрещены при сохранении
- в списке правил показано предупреждение, что первое совпавшее правило по weight всегда побеждает
- добавлены отдельные permissions:
  * view isc redirect rules
  * manage isc redirect rules
  * delete isc redirect rules
  * view isc redirect logs
  * view isc redirect stats
  * administer isc redirect settings
- добавлена страница настроек: /admin/config/search/isc-redirects/settings
- добавлен debug logging и настройки хранения логов/статистики
- улучшена config schema для настроек модуля
- усилена совместимость DI/fallback для matcher и admin form
- в логах теперь есть тип события

Мультиязычность:
- язык материала = язык текущего открытого перевода node
- destination можно хранить без языкового префикса
- при невалидном локализованном destination выполняется fallback на главную страницу нужного языка


Added in this build:
- cache for rules per bundle with tag invalidation on save/delete/update
- update hooks for settings defaults and redirect rule config backfill
