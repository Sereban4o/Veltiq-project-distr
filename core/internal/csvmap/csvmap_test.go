package csvmap

import "testing"

func TestResolveUsesNumericAndNamedMapping(t *testing.T) {
	index := Resolve(Request{
		Header: []string{"Имя", "Email", "Телефон"},
		Labels: map[string]string{
			"first_name": "Имя",
			"email":      "Email",
			"phone":      "Телефон",
		},
		Mapping: map[string]any{
			"first_name": "0",
			"email":      "Email",
		},
	})

	if index["first_name"] != 0 {
		t.Fatalf("first_name index %d, want 0", index["first_name"])
	}
	if index["email"] != 1 {
		t.Fatalf("email index %d, want 1", index["email"])
	}
	// phone was not in the mapping → auto-mapped by its Russian label.
	if index["phone"] != 2 {
		t.Fatalf("phone index %d, want 2", index["phone"])
	}
}

func TestResolveAutoMapsByLabel(t *testing.T) {
	index := Resolve(Request{
		Header: []string{"Название", "ИНН"},
		Labels: map[string]string{"name": "Название", "inn": "ИНН"},
	})

	if index["name"] != 0 || index["inn"] != 1 {
		t.Fatalf("unexpected index %+v", index)
	}
}

func TestResolveOmitsUnresolvableFields(t *testing.T) {
	index := Resolve(Request{
		Header: []string{"Название"},
		Labels: map[string]string{"name": "Название", "email": "Email"},
		Mapping: map[string]any{
			"email": "Нет такой колонки",
		},
	})

	if _, ok := index["email"]; ok {
		t.Fatalf("email should be omitted, got %+v", index)
	}
	if index["name"] != 0 {
		t.Fatalf("name index %d, want 0", index["name"])
	}
}
