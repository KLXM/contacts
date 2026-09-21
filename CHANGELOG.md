# Changelog

## 1.0.0 – in Arbeit

Erste Version.

- Adressbücher mit Rollenrecht je Adressbuch
- Listen: Ein Kontakt kann in beliebig vielen Listen seines Adressbuchs stehen
- Beschriftete Mehrfachfelder für Telefon, E-Mail, Adresse, Website, Datum, zugehörige Personen, soziale Profile und Messenger, mit eigenen Beschriftungen
- Eigene Felder: spontan je Kontakt oder als Vorgabe in den Einstellungen
- Fotos aus dem Medienpool oder aus Kontakte-Apps
- CardDAV als Provider für das Addon `dav`: lesen, schreiben, Gruppen, Fotos, sync-collection. Je Adressbuch abschaltbar.
- vCard-Import und -Export, auch ganzer Adressbücher und Listen
- Kontakte per Drag & Drop auf eine Liste ziehen, auch mehrere auf einmal
- Backend in drei Spalten mit Suche und Sammelaktionen, helles und dunkles Theme, Deutsch und Englisch
- Freigabe für die Website in drei Ebenen: je Kontakt, je Angabe und globale Sperren (etwa „keine Mobilnummern“). Freigaben überstehen den Abgleich mit Apps.
- Modul „contacts: Kontaktliste“ für die Ausgabe ohne Code: Adressbuch oder Liste, Karten, Liste oder Tabelle, Auswahl der Angaben
- PHP-API über die Fassade `Contacts`, für die Website `Contacts::published()` mit bereinigter Sicht
