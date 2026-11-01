<?php

declare(strict_types=1);

/*
 * Header synonyms by canonical field name (the normalised column name of the model / importer
 * column). Languages: en, uk, de, pl, fr, es. A canonical name matches itself and its label too.
 */
return [
    'email' => ['e-mail', 'email address', 'mail', 'електронна пошта', 'пошта', 'імейл', 'емейл', 'e-mail-adresse', 'adres e-mail', 'adresse e-mail', 'correo', 'correo electronico'],
    'name' => ['full name', 'title', 'імя', "ім'я", 'назва', 'піб', 'name', 'nazwa', 'imie', 'nom', 'nombre', 'bezeichnung'],
    'first_name' => ['firstname', 'given name', 'імя', "ім'я", 'vorname', 'imie', 'prenom', 'nombre de pila'],
    'last_name' => ['lastname', 'surname', 'family name', 'прізвище', 'nachname', 'nazwisko', 'nom de famille', 'apellido'],
    'phone' => ['telephone', 'tel', 'mobile', 'phone number', 'телефон', 'номер телефону', 'мобільний', 'telefon', 'téléphone', 'teléfono'],
    'title' => ['name', 'heading', 'заголовок', 'назва', 'titel', 'tytuł', 'titre', 'título'],
    'description' => ['details', 'text', 'опис', 'beschreibung', 'opis', 'descripción'],
    'price' => ['cost', 'amount', 'ціна', 'вартість', 'сума', 'preis', 'cena', 'prix', 'precio'],
    'amount' => ['sum', 'total', 'сума', 'betrag', 'kwota', 'montant', 'importe'],
    'quantity' => ['qty', 'count', 'кількість', 'к-сть', 'menge', 'ilość', 'quantité', 'cantidad'],
    'status' => ['state', 'статус', 'стан', 'zustand', 'stan', 'statut', 'estado'],
    'category' => ['категорія', 'kategorie', 'kategoria', 'catégorie', 'categoría'],
    'created_at' => ['created', 'date created', 'створено', 'дата створення'],
    'date' => ['дата', 'datum', 'data', 'fecha'],
    'address' => ['адреса', 'adresse', 'adres', 'dirección'],
    'city' => ['місто', 'stadt', 'miasto', 'ville', 'ciudad'],
    'country' => ['країна', 'land', 'kraj', 'pays', 'país'],
    'zip' => ['postcode', 'postal code', 'zip code', 'індекс', 'поштовий індекс', 'plz', 'kod pocztowy', 'code postal', 'código postal'],
    'company' => ['organization', 'компанія', 'організація', 'firma', 'entreprise', 'empresa'],
    'notes' => ['note', 'comment', 'comments', 'примітка', 'примітки', 'коментар', 'notizen', 'uwagi'],
    'active' => ['is active', 'enabled', 'активний', 'активна', 'aktiv', 'aktywny'],
    'paid' => ['is paid', 'сплачено', 'оплачено', 'bezahlt', 'zapłacono'],
];
