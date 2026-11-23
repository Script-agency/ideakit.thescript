<?php

declare(strict_types=1);

namespace modules\telegram\application\port;

use modules\telegram\application\dto\IdeaCardView;
use modules\telegram\application\dto\IdeaDetailsView;

/**
 * Получение подготовленного содержимого без изменения состояния пользователя.
 * Идентификаторы непрозрачны для потребителя; демонстрационный источник использует UUID.
 * Отсутствие результата не заменяет ошибку источника.
 */
interface IIdeaContentProvider
{
    /**
     * Возвращает null, если доступного содержимого нет.
     */
    public function first(): ?IdeaCardView;

    /**
     * Неизвестный идентификатор даёт null без подстановки другой карточки.
     */
    public function findCard(string $subjectId): ?IdeaCardView;

    /**
     * Отсутствие подробностей обозначается null, а не пустым успешным ответом.
     */
    public function findDetails(string $subjectId): ?IdeaDetailsView;

    /**
     * Возвращает null, если текущая карточка неизвестна или следующей нет.
     * Циклический обход не является требованием к любой реализации.
     */
    public function nextAfter(string $subjectId): ?IdeaCardView;
}
