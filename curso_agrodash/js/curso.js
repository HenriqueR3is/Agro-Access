const slides = document.querySelectorAll('.slide');
let indexSlide = 0;
const btnProximo = document.getElementById('btnProximo');
const quiz = document.getElementById('quiz');

// Exemplo de perguntas
const perguntas = [
    {pergunta:"Qual a função principal do AgroDash?", opcoes:["Gestão de custos","Monitoramento agrícola","Ambas"], resposta:2},
    {pergunta:"Qual indicador não é monitorado?", opcoes:["Performance","Custo","Temperatura do motor"], resposta:2},
];

btnProximo.addEventListener('click', () => {
    slides[indexSlide].classList.remove('ativo');
    indexSlide++;
    if(indexSlide >= slides.length){
        btnProximo.style.display = 'none';
        quiz.classList.remove('oculto');
        carregarPerguntas();
        return;
    }
    slides[indexSlide].classList.add('ativo');
});

function carregarPerguntas(){
    const container = document.getElementById('perguntas');
    container.innerHTML = '';
    perguntas.forEach((p, i) => {
        let div = document.createElement('div');
        div.innerHTML = `<p>${i+1}. ${p.pergunta}</p>` + 
        p.opcoes.map((o,j)=>`<label><input type="radio" name="q${i}" value="${j}"> ${o}</label><br>`).join('');
        container.appendChild(div);
    });
}

document.getElementById('finalizar').addEventListener('click', async () => {
    let acertos = 0;
    perguntas.forEach((p,i)=>{
        const r = document.querySelector(`input[name="q${i}"]:checked`);
        if(r && parseInt(r.value) === p.resposta) acertos++;
    });
    const percentual = (acertos / perguntas.length)*100;
    if(percentual >= 70){
        const response = await fetch('concluir_curso.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({curso:'AgroDash'})
        });
        const result = await response.json();
        if(result.success){
            alert("🎉 Parabéns! Curso concluído. Certificado desbloqueado!");
            location.reload();
        }
    } else {
        alert("❌ Você precisa de 70% de acertos para concluir o curso.");
    }
});
